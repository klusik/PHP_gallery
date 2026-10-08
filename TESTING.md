# Testing Guide

This guide applies to PHP Gallery Version 0.124.2. Release verification uses the central audit runner's `release` profile as the single authoritative automated qualification pass, plus any material environment-dependent/manual coverage reported by that profile and the retained Version 0.97 coverage: recoverable gallery-subtree deletion, restore, manual purge, bounded Empty Trash, crash reconciliation, optional retention-based automatic purge, persistent protected trash storage, and fail-closed schema readiness; recursive, resumable gallery migration with bounded ZIP packages and imported child-tree reconstruction; canonical map-marker photo-page fallbacks and in-viewer map navigation across physical-gallery pagination, fullscreen split-map persistence, the canonical Admin side-panel mutation envelope and completion coordinator, multi-context postcondition verification, stale/out-of-order suppression, browser upload pipeline safeguards, opened-gallery branch image counters and their Theme/per-gallery visibility policy, progressive thumbnail dimension detection and responsive compatibility, the Version 0.93 request-budget/TTFB behavior, request-local database caching, resumable updater safety, updater server-policy reconciliation, Admin test-run diagnostics, public media concurrency and cache invalidation, clean-home URL handling, upload auto-renaming and inventory behavior, the redesigned Windows uploader, the Windows HTTP monitor schedules/protocol snapshots/report ZIPs, deployment exclusion rules, lightbox detached-image cleanup, decoded-cache ownership, preload-generation invalidation, navigation-transaction settlement, recoverable loading failures, teardown/reopen cycles, public lightbox zoom and progressive quality promotion, Shift+Left/Right ten-photo navigation, public Smart Gallery visibility, presentation settings, cycle-safe placement/order evaluation, viewer account privacy/access, collection sharing, bounded gallery benchmark diagnostics, access intersection and pagination; multilingual gallery/photo content and fallbacks; browser-local ZIP imports; progressive gallery and Smart Gallery ZIP downloads; browser download symbol rendering; ordered migration upgrades; complete deployment packaging; updater safety; the configurable public language selector; hourly automatic-update throttling; and the supported English, Czech, German, and Swedish catalogs.

## Purpose

Retained Version 0.107 thumbnail acceptance: open a gallery editor, run **Create all thumbnails** with and without **Include subgalleries**, and confirm only the selected branch is processed. Confirm browser generation is initially selected there and in the all-gallery Maintenance card, server generation remains selectable, progress completes in place, and every gallery-editor tab still responds after the Images tab is opened. For the Metadata Organizer, verify a successful move changes the physical file location and an induced move failure gives a safe reason while the pending journal remains available for reconciliation. The central release audit owns automated verification; these are manual browser and filesystem acceptance checks.
This project is a plain PHP gallery CMS without a formal browser automation stack. Automated verification is centralized through `scripts/audit.php`; focused commands documented later are diagnostic and manual-acceptance tools, not a second test plan that agents should execute in addition to the audit.

## Database support evidence

The project-tested database/PHP representatives are MySQL 8.4/PHP 8.3,
MariaDB 10.11/PHP 8.3, and MariaDB 11.4/PHP 8.5. Their matrix jobs require the
disposable real-database workflow; a missing fixture is a failure in CI. The
separate PHP 8.1 job is source-only and does not qualify a database combination.
Other server series, including newer vendor-maintained or innovation releases,
remain unqualified until directly represented by required CI. See
[Database support](docs/DATABASE_SUPPORT.md) for status definitions, concrete
SQL assumptions, and upgrade guidance.

### Version 0.108 gallery creation and editor acceptance

Use a disposable, migrated installation with two administrator accounts and a gallery containing a nested branch. The central **release** profile owns automated regression; the following is the release-specific human browser/upgrade matrix and must be recorded as actual evidence rather than inferred from source tests.

1. Open the public gallery page as an administrator and use `+` on a root and on a child gallery. Confirm the right-side create panel contains only the name field, retains the intended parent, and offers bounded title completion. Accept a suggestion by keyboard and by pointer; dismiss one with Escape. Create a gallery and confirm the full editor replaces the create content in the same panel, the public page URL does not change, and the new gallery starts Unpublished.
2. On a narrow viewport and on a desktop viewport, switch through Identity, API, Access, Display and Media by physically clicking the visible tabs, including with a wrapped panel title and after scrolling. Confirm each tab reveals its own content, the panel remains open, and **Save gallery** stays reachable at the bottom while editing settings. Save, reopen the panel and confirm persisted values; the displayed public gallery/card refreshes without a page reload.
3. In Identity, verify that date/date range remains among ordinary fields, the description formatting `?` opens and closes with pointer and keyboard, the source language shows its flag, **Other languages** remains expandable, and AI gallery text is under Advanced. Save tags and a chosen language, reopen, and verify both. Check that a saved SimBrief identifier and language show a quiet pre-filled note in a later gallery editor.
4. With migration `202609250001_gallery_creation_preferences.php` applied, save a numeric SimBrief identifier and then a text pilot name as defaults; verify the obsolete alternate slot is cleared, each value is isolated to its administrator, and an unselected Remember option does not overwrite an existing default. Before applying the migration, confirm ordinary title-only creation remains available and an explicit request to remember defaults receives migration guidance without creating or editing the gallery. Treat an unknown schema-inspection result as unavailable for writes.
5. If SimBrief is enabled, import an OFP before creating a gallery. Check the editable description preview, private draft reference, 30-minute expiry and current-session ownership. Changing the identifier clears the reference; editing the description while a request is in flight preserves the user's text and refuses the stale import. After creation, verify the OFP and available route map attach to the new gallery. Simulate optional attachment failure and confirm the created gallery remains and shows a warning. Confirm an unavailable optional route-map schema does not claim a successful route write.
6. On the API tab, copy the upload endpoint and newly generated one-time API key, inspect clipboard feedback, and confirm the active-key list is omitted when empty and appears after generation. Revoke a key from the open panel and confirm the tab remains usable after its fragment refresh. In Access, verify compact visibility/password/share controls, the small NSFW checkbox and on-demand explanations. In Display, verify grid controls appear first, Picture Game is inside Advanced, and EXIF/GPS, card format, count badge, lightbox and responsive thumbnail bounds are available in that closed-by-default section. Save representative selections and reopen to check persistence.
7. Open the direct Admin create page with JavaScript disabled and confirm the normal POST path remains usable. Its More options disclosure contains folder, parent, visibility, date and less common toggles; the separate create-and-upload form still accepts files. On validation failure, confirm submitted non-secret values and parent context remain available for correction. Repeat a completed form submission with the same operation key and verify the replay ledger returns the first result rather than creating another gallery.

The relevant automated owners are `tests/gallery_creation_preferences_test.php`, `tests/gallery_editor_creation_defaults_save_test.php`, `tests/public_gallery_create_entrypoint_test.php`, `tests/simbrief_create_draft_test.php`, `tests/gallery_display_editor_layout_test.php`, the gallery edit concurrency contract, and the registered panel lifecycle/browser fixtures. Their presence is not a substitute for the migrated database and actual browser review above.


## Central Audit Runner

### Agent execution rule

Automated agents must use the central audit runner as the default and authoritative verification interface. **This rule overrides later wording such as "run these tests", "run", or lists of focused PHP/Node commands elsewhere in this guide.** Those lists document coverage ownership and give humans/agents precise reproduction commands after a failure; they are not instructions to replay the regression tree before or after a successful central audit.

For normal agent work:

```text
edit/debug cycle          php scripts/audit.php --profile=quick
final code handoff/ZIP    php scripts/audit.php --profile=full
actual release            php scripts/audit.php --profile=release
```

Do not enumerate `tests/`, do not create shell/PowerShell loops over test files, do not run every focused command listed in a feature section, and do not separately run whole-tree `php -l`/`node --check` passes when the selected audit profile already performs them. Run a focused command only to reproduce a specific audit failure, develop/register a new test, perform genuinely manual/browser acceptance outside the automated runner, or satisfy an explicit user request. Once diagnosis is complete, rerun the appropriate central profile once rather than manually replaying sibling tests.

The central runner intentionally suppresses passing child stdout to protect agent context. Read the console summary first; read `cache/test-audit/latest.md` only when needed; open raw suite logs only for `FAIL`, `BLOCKED`, or a materially relevant `SKIP`. A successful suite does not justify reading its raw log.

The MVC boundary suite is a zero-baseline contract. `scripts/mvc_boundary_baseline.json` contains no reviewed legacy occurrences; therefore every strict scanner finding is new and must fail the audit. Do not add exemptions or repopulate the baseline to make a change pass. Refactor the ownership violation instead. The same suite now writes `<run-directory>/mvc-architecture.json`, a machine-readable whole-runtime inventory produced with `token_get_all()` without including or executing the inspected PHP files. Its `runtime_inventory.review_candidates` section is advisory historical debt, not a pass/fail baseline; the compact audit summary reports the candidate count so it can be driven toward zero and later promoted into hard rules.


```bash
php scripts/audit.php --profile=quick
php scripts/audit.php --profile=full
php scripts/audit.php --profile=release
```

`quick` runs `php-fast`: the explicit `quick_tests` subset in `scripts/audit_php_registry.php`, selected for audit infrastructure, core/bootstrap/routing, security, and mutation contracts. It also runs MVC boundaries, the whole-tree Python import policy, strict changed-source documentation/policy checks, fast Node fixtures, mutation/version contracts, clean-child runtime performance probes, and changed PHP/JavaScript syntax checks. It excludes the complete PHP regression tree, whole-tree source inventory and debt budgets, WinApp tests, slow Node work, and Chromium. If Git metadata is unavailable, changed-file linting safely falls back to the full source tree. A quick PASS does not replace full handoff verification.

`full` retains the complete PHP regression tree, whole-tree source inventory and debt budgets, WinApp tests, all deterministic source checks, the slow ZIP64 boundary fixture, full PHP/JavaScript syntax validation, and available Chromium fixtures. It also records runtime performance probes. `release` retains all full coverage and adds release consistency, source-fingerprint binding, `app/core-manifest.json` freshness, and `git diff --check` when checkout metadata is available. The historical browser suite ID remains `browser-map`; its label is Chromium browser integration. `php tests/run.php` still delegates to `--suite=php-regression --no-report` and always means the complete PHP suite.

`patch_notes_ai_evidence_test.php` runs in the full/release PHP suite with an exclusive process barrier. It exercises output larger than pipe buffers, exact metadata bounds, generic diagnostic refusal, forced deadline cleanup and byte-for-byte complete UTF-8 text. Its owned Git repository executes the actual release prompt CLI against a multi-megabyte diff, checks content from later changed files and generated-artifact exclusion, and verifies that oversized immutable metadata fails without creating a partial prompt. The CI workflow contract also retains the evidence step's independent two-minute timeout. These fixtures collect local evidence only; they do not call Copilot, prepare release metadata or compile PDFs. The companion `tests/patch_notes_ai_chunks_test.php` exercises complete source-byte reconstruction and SHA-256 provenance, multibyte/oversized-line boundaries, missing and tampered responses, bounded final synthesis and an additional digest-reduction level. The release workflow executes the actual Copilot CLI once per mandatory diff slice (and for any required reduction levels) before final Markdown validation. Feature CI verifies deterministic chunk orchestration but cannot validate Copilot entitlement, provider quotas or editorial quality without a release run using `COPILOT_GITHUB_TOKEN`.

The release-note evidence regression also verifies that the generation contract and validator share all three mandatory main headings, including for tooling-only releases with no direct public behavior change. Missing headings, inline mentions, renamed headings and incorrect heading levels must be rejected with the exact section name. Bounded evidence reduction must preserve this contract in its final prompt. The CI workflow contract keeps failure-only retention of raw model prose in `release-notes-rejected-response` for seven days; prompts and authentication diagnostics are excluded. Real Copilot output remains subject to strict validation before any notes are applied.

### Clean PHP include-phase probes

`production_file_policy_test.php` validates canonical positive membership, safe contained paths, additive companion membership and source-archive activation. `updater_inventory_compatibility_test.php` runs a trusted frozen pre-WinApp reader against the real incoming inventory and an extracted-source fixture: it reproduces the reported package rejection, checks compatible old/current activation sets, and retains unlisted-file refusal. Keep this installed-consumer proof when changing inventory schema or base path surfaces. `updates_path_safety_test.php` checks updater destination ancestors. The full/release regression `deploy_app_packaging_test.php` runs the actual deploy helpers on owned dirty fixtures and inspects folder/ZIP contents, required-file failures and local source-review/media choices; its complete-inventory proof has an exclusive 600-second process budget. The `production-package` CI job independently builds and verifies production folders on Linux, Windows and macOS. See [production file policy](docs/PRODUCTION_FILES.md).

The same regression exercises repeated interactive Windows folder/ZIP builds under the Windows PowerShell runtime used by `deploy.bat`, including timestamp collisions, and verifies earlier packages remain unchanged. An interactive destination collision selects a fresh sibling directory and prints its path; an explicit `-DeployFolder` keeps the existing refusal to overwrite. Launches without arguments show success/failure completion and wait for Enter before closing. Scripted invocations with arguments finish without pausing.

`tests/cli_http_boundary_test.php` exercises real direct HTTP requests to CLI
entrypoints through a PHP server without rewrite rules, checks empty refusals
before bootstrap/mutation, and retains CLI execution. Its isolated Apache fixture
loads the shipped internal-tree authorization rules without `mod_rewrite` and
checks public query-string routing. Set `PHP_GALLERY_APACHE` and
`PHP_GALLERY_APACHE_PHP_MODULE` to the local executables/module when automatic
discovery is unavailable. Missing Apache coverage is reported explicitly; it is
separate from the always-required PHP HTTP boundary checks.

Every profile starts `scripts/audit_runtime_probe.php` as a fresh CLI child for the `early-runtime` and `application-bootstrap` entry sequences in `scripts/audit_performance_registry.php`. The first includes the real `app/early_runtime.php`; the second includes `app/early_runtime.php`, `app/diagnostics/admin_test_run_early.php`, then `app/bootstrap.php`. These include-only probes stop before `cms_run()` and do not start sessions, load installation configuration, access a database, or dispatch a request. The separate registered runtime-performance suite measures actual route lifecycles through the public entrypoint when the runner receives an owned disposable workflow fixture.

The curated quick registry also runs the MVC scanner's paired fixtures. Ordinary
route `prepare()` calls must remain distinct from typed PDO receivers, aliases,
construction and SQL-bearing calls. Provenance must stay local to its declaration
scope and assignment order. The architecture JSON retains raw observations and
reports accepted Core boundary path/signal pairs with their purpose and evidence;
accepting a response or diagnostic signal never exempts unrelated responsibilities
in the same file. Session-boundary fixtures assert actual key values, one-time
consumption, language separation, OAuth expiry and gallery-grant expiry rather
than testing only that superglobals disappeared from source.

Included PHP files and peak memory are hard ceilings: early runtime is 1 file and 16,777,216 bytes (16 MiB); application bootstrap has a 24-file baseline, a 40-file ceiling, and a 16,777,216-byte (16 MiB) ceiling. Wall time is observational and never compared to a machine-specific limit. Each include-probe entry in the audit report's `details.metrics` has `schema_version: 1`, `probe`, `scope: include-only-before-cms_run`, `php_version`, `php_int_size`, `included_php_files`, `included_paths`, `bootstrap_wall_ms`, `peak_memory_bytes`, and `limits`. The compact Markdown report prints raw file counts, byte counts, and wall milliseconds.

### Route lifecycle performance probes

The registered `runtime-performance` suite runs each route in a fresh isolated PHP child and exercises the real `public/index.php` request path through `cms_run()`. Its nine cases cover robots, home, a seeded public gallery, an authorized thumbnail, authorized media, authenticated Admin dashboard and telemetry, and anonymous denials for both Admin routes. The authenticated cases log in through the disposable fixture and use the session created by that real login. A successful route must return HTTP 2xx and a nonempty body that matches its registered content contract: robots directives, stable product-page markers, or recognized raster-image metadata. The child reports `response_contract_matches`, and a generic error page with HTTP 200 fails this guard. Content validation happens after capturing resource metrics. A denied Admin redirect may return HTTP 302 with an empty body. Route identity, authentication mode, expected and actual outcome, status, included application PHP paths/count, memory, response byte count/hash, content contract, and fatal status are validated in machine-readable JSON. Only included-file count and peak memory are regression ceilings; measured wall time remains observational.

| Route case | Expected context | Maximum included PHP files | Peak-memory ceiling |
| --- | --- | ---: | ---: |
| `robots` | Anonymous success | 160 | 32 MiB |
| `home` | Anonymous success | 230 | 40 MiB |
| `gallery` | Anonymous success | 300 | 48 MiB |
| `thumb` | Anonymous success | 200 | 40 MiB |
| `media` | Anonymous success | 200 | 40 MiB |
| `admin` | Authenticated success | 270 | 48 MiB |
| `admin_telemetry` | Authenticated success | 200 | 40 MiB |
| `admin_denied` | Anonymous denial; same route ceiling as Admin | 270 | 48 MiB |
| `admin_telemetry_denied` | Anonymous denial; same route ceiling as telemetry | 200 | 40 MiB |

These are in-process application lifecycle measurements, not HTTP-server, network, or TLS latency measurements. The child buffers the response to record body size and SHA-256; it does not retain page contents. When no owned `GALLERY_WORKFLOW_FIXTURE` is available, the route suite reports an explicit `SKIP` rather than treating include-only probes as route coverage.

For local qualification with a private MySQL server, use the existing disposable workflow wrapper. Set `GALLERY_WORKFLOW_ENABLE=disposable-only` and `GALLERY_WORKFLOW_MYSQL_BIN` to a MySQL 8 `mysqld` executable, then run `php scripts/gallery_workflow_mysql.php --audit` for full qualification or `--audit-quick` for fixture-backed quick feedback. The wrapper initializes a private data directory, creates a dedicated generated database account, starts the migrated application copy and HTTP fixture, and cleans up its owned resources afterward. Both modes require all nine route measurements; quick retains its curated regression subset, while full additionally requires database/HTTP/concurrency and enabled browser workflow PASS records. This local MySQL wrapper does not qualify MariaDB; the required CI matrix runs the same central real-database workflow against each listed representative. Never point this workflow at the active Gallery configuration or database. Without this wrapper, ordinary quick/full/release audit runs still execute the include probes and mark the real-route suite `SKIP` when the fixture is absent. The historical `--quick` convenience flag still delegates directly without provisioning a fixture.

`scripts/runtime_plan_baseline.json` also protects the nine routes and the shipped
module graph without requiring a database. The reviewed post-composition graph is
the structural baseline; included-file counts and memory come from the disposable
pre-change route capture. File counts and global direct entries allow the larger
of four files or five percent; transitive and global module counts allow two
additional modules. Route roots must stay unchanged, and duplicated direct file
ownership cannot grow. Peak memory allows one 4 MiB allocation margin. Wall time
is recorded without a threshold. The prior flat graph remains diagnostic evidence,
not a permissive future limit. A deliberate ownership or budget change requires
reviewing and updating this baseline rather than raising the broad route ceilings.

The module-plan fixture compares composed and flattened legacy loading in separate
fresh PHP processes for every logical module. It checks actual include order,
including files required by module entrypoints, callable route handlers, and no
include-time output, headers, sessions or umbrella loading.

The registered 240-second limit covers full graph compilation and both clean-child loaders; the measured Windows run took 72 seconds. The complete `runtime_module_plan_test.php` compiler/freshness and composed-versus-legacy clean-child matrix belongs to `full`/`release`. `quick` retains `runtime_dependencies_test.php`, `runtime_plan_ratchet_test.php`, runtime kernel/route contracts and fresh-process include probes, without starting every logical module in both loaders on each edit. This changes feedback scope only; full release coverage is unchanged.

### PHP worker scheduling

Both `php-fast` and `php-regression` use the same portable `proc_open` worker scheduler. The default is four workers; set `PHP_GALLERY_AUDIT_WORKERS` to an integer from `1` through `8` to override it. Invalid values produce BLOCKED coverage. Parallel-safe tests run within this bound. Explicit `serial_tests` entries in `scripts/audit_php_registry.php` include their reason and are merged into central PHP requirement metadata as `serial`/`serial_reason`. Before each exclusive test, all active workers finish; no other test starts until that exclusive test finishes. Tests with contention, shared resources, servers or their own child processes must be reviewed for this policy when registered.

Each child has isolated file-backed stdout/stderr captures, including on Windows. Its timeout starts when that child actually starts, and a timeout terminates that process, attributes FAIL to its test, and leaves other scheduled tests able to finish. Results and failure logs use deterministic input order rather than completion order. Failure details include the test name, exit code or timeout, and captured diagnostics. Full regression still discovers every `tests/*_test.php` entry; the fast subset is centrally curated rather than inferred from filename patterns.

PHP/JavaScript syntax checks and independent Node/Chromium fixtures use the same bounded worker scheduler and `PHP_GALLERY_AUDIT_WORKERS` limit. PHP lint uses `-n -l`: it parses without loading php.ini or extensions and never executes application code. PHP regressions dispatch the parallel batch before exclusive fixtures, preserving the relative order of exclusive tests and the original report order while draining the pool only once. Node registry entries may declare `serial` when a fixture owns shared resources; current independent browser fixtures use unique profiles and loopback ports. Node reports retain per-test outcomes and timings.

The MVC scanner indexes namespace/import context changes once per file and reuses the active context at each token in strict MVC, Core persistence and architecture scans. PDO provenance, namespace transitions and source-position aliases retain their regression coverage; it no longer walks every preceding token for each function call.

The source policy scanner preserves declaration identities and records while skipping unused enclosing-body fingerprints; changed-declaration enforcement still hashes executable bodies. Complete release coverage includes serial database/recovery/contention scenarios, so elapsed time depends on the machine and configured fixtures. Use the recorded suite and child durations to locate remaining costs rather than dropping coverage.

### Required Chromium CI

`.github/workflows/gallery-workflows.yml` contains one `browser-tests` job on PHP 8.3 and Node 22. It explicitly discovers Chrome/Chromium, prints its version, exports the executable as `PHP_GALLERY_BROWSER`, sets `PHP_GALLERY_BROWSER_REQUIRED=1`, and runs `php scripts/audit.php --suite=browser-map`. This executes the registered Node entries marked `browser` through the existing confined headless Chromium fixtures. No npm install, Playwright, Selenium, or Composer stack is added. Missing/unstartable Chromium, skipped required fixtures, or zero executed browser coverage produce nonzero failure or BLOCKED coverage.

Database/runtime matrix jobs set `PHP_GALLERY_BROWSER=disabled` and `GALLERY_WORKFLOW_BROWSER=disabled` in their own job environments to avoid redundant Chromium execution. Local browser coverage remains optional unless required mode is explicitly enabled. The dedicated job covers isolated DOM/runtime fixtures; the disposable migrated-database browser journey described in [GALLERY_WORKFLOWS.md](docs/GALLERY_WORKFLOWS.md) remains a separate opt-in path.

Browser failures print the fixture filename and its specific `BROWSER FAIL` assertion or runtime error directly in the central audit console and Markdown report. Console output is bounded to five problems per suite; full child output remains in `browser-map.log` inside the `required-chromium` Actions artifact, retained for seven days. `Report: cache/test-audit/latest.md` is a runner-local path, not a downloadable GitHub link. Open the run's **Artifacts > required-chromium** ZIP for `latest.md`, `latest.json`, and the timestamped suite log.

The shared Admin browser harness retains a bounded Chrome stderr tail and page exceptions on failure and reports the last assertion progress when its deadline expires. Browser process and fixture deadlines remain hard limits; failures are not retried into success. For asynchronous UI completion, await observable postconditions with a bounded deadline instead of assuming a short timer proves completion. The SimBrief review contract waits up to two seconds for native close-event cleanup, then independently checks dialog closure, editor visibility, unchanged URL, focus containment, and background isolation. It exercises repeated native Cancel clicks at 320/390/1280px and after replacing the editor fragment; cancellation must not submit a dispatch.

### Recovery, real workflows, and release evidence

- [Recovery assurance](docs/RECOVERY_ASSURANCE.md) describes the read-only recovery evidence CLI and synthetic corruption/omission drill. [Off-host recovery](docs/RECOVERY_OFF_HOST.md) records the required hosting/operator checks. Fixture PASS does not prove production recovery.
- [Isolated workflows](docs/GALLERY_WORKFLOWS.md) describes the generated MySQL database, disposable application copy, real HTTP/browser journeys, and mandatory MySQL/MariaDB CI job. The opt-in PHP workflows and existing concurrency test run through the central audit; absent local prerequisites are explicit SKIPs, while required CI coverage cannot skip. Never configure these tests against the Gallery database.
- [Title completion](docs/TITLE_COMPLETION.md) documents server budgets, matching behavior and synthetic measurements. The real DOM-event fixture covers IME, Unicode, selection, modified keys, two-stage Escape, pointer acceptance, replaced forms and stale responses. Human assistive-technology review remains separate.
- [Browser lifecycle](docs/BROWSER_LIFECYCLE.md) defines the single nearby-preview queue owner and its reset/disposal contracts. Runtime seam tests cover both thumbnail renderer labels and repeated owner replacement.
- [Release qualification](docs/RELEASE_QUALIFICATION.md) binds human review and central release reports to exact source/PDF bytes. Automated PASS never marks PDF or manual browser checks approved.

The central process runner captures stdout/stderr in temporary file-backed streams, preventing verbose failing assertions from blocking Windows pipes and bypassing timeouts. Registered PHP fixtures may declare a bounded setup-aware timeout; ordinary tests retain the default. Disposable workflow runners only provision/clean fixtures around the same central audit, not a parallel test suite.

The runner captures subprocess stdout/stderr instead of streaming passing-test noise. Its console output is deliberately compact, but it prints and flushes a `RUN` line before every suite and that suite's normalized result immediately after completion, so long Windows process-spawn phases never look like a hung command. Normal persisted output is:

```text
cache/test-audit/latest.md
cache/test-audit/latest.json
cache/test-audit/<run-id>/report.md
cache/test-audit/<run-id>/report.json
cache/test-audit/<run-id>/mvc-architecture.json
cache/test-audit/<run-id>/*.log
```

Read `latest.md` first. Open a suite log only when that suite is `FAIL` or `BLOCKED`, or when diagnosing a `SKIP`. Status has strict meaning: `PASS` executed and passed; `FAIL` executed and detected a regression; `SKIP` is intentionally unavailable optional coverage such as a missing real browser or self-skipped database integration; `BLOCKED` means required coverage could not execute because its source/dependency is unavailable. The overall command exits `0` on PASS, `1` on FAIL, and `2` on BLOCKED.

Node execution is registry-driven through `scripts/audit_registry.php`, not a blind `*_test.mjs` glob. This is required because ZIP writer fixtures need temporary output arguments, the ZIP64 test is deliberately slow, and the real lightbox browser test needs a Chromium-family executable. Per-test PHP environment requirements also belong in that registry. `tests/audit_runner_test.php` prevents unregistered Node tests and profile drift.

Breadcrumb settings cover nine stable registry IDs, physical and Smart Gallery overrides, inheritance/fallback resolution, omitted-field preservation, persistence, and semantic escaped markup. Theme, physical-gallery, and Smart Gallery controls must show each style as a native radio card with a real sample rendered through production styles; the inherit sample must reflect the effective Theme choice. Verify keyboard selection and all controls with JavaScript disabled, and confirm decorative sample ancestors create no links or extra navigation landmarks. The registered `breadcrumb_browser_test.mjs` uses the production renderer and stylesheet cascade to verify all nine styles and deep paths at desktop and 320px widths, including keyboard focus. Every public ancestor remains reachable through wrapping; no client-side initializer is required. The serial `breadcrumb_workflow_integration_test.php` exercises authenticated Theme/gallery saves, public inheritance/override rendering, canonical panel completion, invalid-input atomicity, sidecar persistence, and Trash restore against the disposable workflow fixture. Gallery, access-gate, Picture Game, tag, and Smart Gallery navigation share the renderer; Smart Gallery image-source provenance remains separate.

The registered map browser fixture also exercises saved route maps with zero photo cards, both with and without lightbox markup. It checks complete route coordinates, route-point marker roles, fit/reset controls, unchanged URL, teardown and reinitialization, plus the normal no-coordinate empty state. Its Leaflet boundary is a test double; it does not qualify live map tile providers.

The registered `lightbox_dev_dashboard_browser_test.mjs` runs the actual viewer against isolated synthetic media. It covers disabled DEV without loading the dashboard module, a warm immutable obsolete module cache followed by the current application revision, current media identity and quality, sanitized source URLs, graph legends/scales, persistent section selection, Freeze/Resume, rapid navigation, quality failure warnings, fullscreen subtree ownership, no diagnostic media requests and teardown. Its compact and expanded views contain no scrolling regions; Expand is a separate control beside Freeze and Collapse. The central full audit owns this coverage. For visual qualification, check compact/expanded views and each detail page in normal/native fullscreen at desktop, narrow portrait and short landscape sizes; retain access to viewer controls and confirm long filenames/URLs fit without scrollbars.

Python discovery is execution-based rather than filesystem-only. The runner probes `python3 --version`, `python --version`, and `py -3 --version` in order and accepts only a successful Python 3 response. This is intentional on Windows: App Execution Aliases and launchers can be runnable from `PATH` even when PHP does not expose the alias as an ordinary file to `is_file()`. `PHP_GALLERY_PYTHON` remains available as an explicit executable-name/path override and is validated by the same Python 3 probe before WinApp tests start.

WinApp tests that exercise optional host integrations must remain isolated from the developer workstation. In particular, SimConnect tests must not assume that a deliberately invalid manual override means no usable SimConnect DLL exists: the runtime intentionally falls back to automatic DLL discovery. The regression fixture therefore stubs DLL resolution when testing the nonfatal missing-DLL branch. The audit also parses Python `unittest` trailers so the compact console/report summary distinguishes passed tests, assertion failures, errors, and skips without dumping the full Python log.

`winapp/tests/test_simconnect.py` uses fake native DLL calls and real packed
ctypes packets to cover MSFS 2020 aircraft position, MSFS 2024 camera/fallback,
unknown-generation capability handling, explicit exceptions, response
correlation, bounded waits, cleanup, repeated acquisitions and nonfatal upload
integration. Independent wire-format packets cover nonzero reply object IDs,
request/definition mismatches, the 40-byte metadata header plus three FLOAT64
values, truncated payloads and valid zero coordinates. Diagnostics checks cover
bounded receive metadata/dispatch sequence, specific rejection reasons,
redaction preserving the primary failure and one displayed SimConnect marker.
Build tests verify the x64 runtime, required exports, embedded DLL
hash and the installer entry that distributes only the application EXE with its
embedded runtime. These tests run through the central WinApp regression suite.

The installer contract also covers forced shutdown before file replacement,
exact installation-path matching, verified exit, and refusal on unknown state.
Compile the real script with the existing Inno Setup compiler. Manual upgrade
acceptance must check a running/tray uploader and both onefile processes, silent
installation, an uploader copy in another directory, and refusal when shutdown
or WMI verification is unavailable. Compilation/source contracts do not prove
live shutdown behavior; WMI query latency is controlled by Windows.

Self-update regression uses fake releases/transports to cover numeric versions
across paginated CMS releases, stable filtering, identical/conflicting duplicates,
highest-version missing hashes, metadata/URL limits, same-release manifests,
streaming hash/size refusal, cancellation and unrelated partial-file preservation.
UI/helper fixtures exercise hourly scheduling, safe drain, ticket ownership, exact
target identity and elevated installation. AST contracts enforce documented,
typed definitions and absence of future imports in new updater modules/tests.
The central WinApp suite owns these checks; tests never download a live installer.

Frozen updater regression must distinguish the bundled `VERSION` text file from
Windows `version.dll`. Source tests cover explicit `version.dll`, `kernel32.dll`
and `shell32.dll` names with System32 search; build qualification also runs the
read-only native API smoke switch in a copied frozen helper before ISCC.
The smoke check must not install, elevate or mutate updater state. Verify an
installed 0.3.2 application's future helper handoff on Windows. A 0.3.1 app copies
its old helper before installing 0.3.2 and may still show the earlier DLL error;
manual installation of the verified 0.3.2 installer avoids that old-helper path.

Manual installed-Windows acceptance covers automatic/manual checks, UAC acceptance
and cancellation, a running/tray uploader and both onefile processes, active
uploads/watchers/media preparation, worker-drain timeout, genuine installer
progress, disconnects, and restart as the original user with saved settings/jobs.
Self-update must refuse replacement while the exact installed process remains,
preserve unrelated copies and never force-kill workers. Verify native Python/source
mode's manual installer workflow. Installation failure has no automatic rollback.
Fakes/source contracts do not establish live UAC, pipe progress or target-machine
process behavior.

The incorrect reply-object-ID filter is a confirmed code bug, but the reported
FS2020 timeout did not record the returned object ID. Do not claim that a nonzero
ID was observed or that fake tests establish its live fix. Retest on the affected
PC and retain the bounded receive/rejection diagnostics if acquisition still fails.

Real-simulator acceptance requires separate Windows runs with MSFS 2020 and
MSFS 2024: capture a watched screenshot during a flight, confirm provider/source
and degrees/feet in logs, and verify the uploaded image's map position. In 2024,
compare camera coordinates with an external/drone camera, then make the camera
unavailable and verify aircraft fallback. With the simulator closed, upload must
still succeed. Capture several screenshots quickly and confirm per-file metadata
and optional source deletion. In an installed build, check that the local log
resolves the bundled `runtime/simconnect/SimConnect.dll` in PyInstaller's
extraction directory. Copy diagnostics must show the last result without triggering a
new connection. Fake-DLL tests and packaging checks cannot establish live native
compatibility or simulator response timing on another workstation.

`php tests/run.php` is retained only for compatibility and delegates to `scripts/audit.php --suite=php-regression --no-report`. It is not an agent entrypoint. Focused commands elsewhere in this document are reproduction/diagnostic references or manual acceptance steps only; the global agent execution rule above takes precedence over them. Do not pre-run focused tests "just in case", and do not replay them after a successful central audit. Duplicate runs are justified only while investigating a concrete failure or validating a new test before registry integration.

## Gallery integrity and panel integration

Use the central audit for these checks, not a second manual test loop. The
disposable MySQL workflow wrapper accepts `--quick` during implementation and
`--audit` for the final full profile. Explicit fixture opt-in and validated
MySQL/browser executable paths remain mandatory; it must never reuse live
configuration, galleries or database state.

The registered regression tree now includes displaced-folder catalog
preservation, owned-worker image-move interruption/recovery, independent-session
edit revisions, portable duplicate-column migration replay, pre-activation enforcement refusal, replay-safe
operation keys, raster decode admission and bounded destination search.
Full-profile browser fixtures cover panel load ownership, native keyboard focus,
in-memory drafts and parent-picker integration. A passing Node seam test is not
reported as real browser interaction.

The source contract task stores complete value-free inventories under the
individual audit run directory. Its PASS means discovery succeeded and reliable
category counts stayed within reviewed historical caps; the console still reports
remaining documentation/policy debt. Changed-declaration/MVC contracts remain
distinct enforcement checks. Manual mobile, Firefox/WebKit and
assistive-technology acceptance are not inferred from Chromium results.

## Canonical Feature Capability Policy

The optional-feature architecture is protected by five focused PHP contracts that are registered in the normal PHP regression suite:

- `tests/feature_policy_core_test.php` validates canonical definitions, configured/effective state, dependency semantics, simple and compound route ownership, registry validation, and compatibility wrappers.
- `tests/feature_policy_adapters_test.php` validates default feature-flag storage, explicit app-setting storage, domain adapters, subordinate-setting preservation, and idempotent fresh-install seeding.
- `tests/feature_policy_admin_test.php` validates grouped Admin > Features state, stale revision rejection, dependency blockers, configured checkbox state, and bounded health presentation.
- `tests/feature_policy_inventory_test.php` statically audits registered capability literals, owned UI/routes, Admin navigation/settings metadata, and feature-specific guard placement.
- `tests/feature_policy_stage13_contract_test.php` applies reusable OFF/ON route checks across the registry, dependency recovery, bounded/redacted health diagnostics, non-destructive data policy, and early exits for expensive optional work.

These tests do not replace subsystem regressions. Multilingual content still proves loader suppression and translation recovery, Gallery Trash tests still own Trash data/settings behavior, Viewer tests still own account data, and feature-specific tests still own Smart Gallery, duplicate detector, tag, updater, database-maintenance, favicon, benchmark, and thumbnail behavior. The capability tests protect the cross-feature orchestration boundary.

For manual Admin acceptance after a policy change, verify one enabled and one disabled capability in **Admin > Features**, one dependency-blocked capability when applicable, a direct owned route, the corresponding navigation/control visibility, and OFF -> ON restoration of already stored subsystem state. On a mixed core page, verify read-only/core actions remain reachable while only the disabled optional mutation is refused. Do not trigger every optional schema merely by opening Admin > Features.

## Multilingual Content

Run `php tests/content_localization_model_test.php`, `php tests/admin_content_localization_test.php`, `php tests/public_content_localization_test.php`, `php tests/openai_text_assist_model_test.php`, `php tests/public_language_preference_test.php`, `php tests/translation_catalog_consistency_test.php`, and `php tests/migration_consistency_test.php`.

Coverage includes unclassified existing content, all maintained languages, invalid-language rejection, independent gallery-field fallback, non-mixed photo caption variants, blank-row deletion, batch/cache behavior, side-panel FormData ownership, access-before-localization ordering, server-rendered cards/lightbox/SEO, translated search terms, sidecar transfer, and review-only provider drafts. Translation behavior must not alter slugs, paths, ordering, filenames, visibility, access, NSFW, or media authorization. Finish with syntax checks for changed PHP files and `php scripts/audit.php --profile=full`.

## Path Resolution In Split Modules

`tests/module_split_path_resolution_test.php` protects the one regression class
that a mechanical module split introduces without changing a single byte of a
function body: a part file lives one directory deeper than its module entry
file, so a moved `dirname(__DIR__, 2)` resolves to `app/` instead of the project
root. The code still parses and still runs; it just reads and writes the wrong
location, so neither `php -l` nor a byte-for-byte function comparison detects it.

The test discovers every part directory in the repository, ignores comments so a
docblock may warn about the wrong depth, proves each `dirname(__DIR__, N)`
resolves to the repository root, and then asserts the concrete outcomes that
matter: Admin test-run storage stays at `cache/admin-test-runs`, migration job
storage stays at `cache/gallery-migrations`, neither moves inside `app/`, the
migration instance identifier stays derived from the repository root because it
feeds migration job identity, and SQL callsite reporting still excludes the whole
instrumentation module. Extend it whenever a split module gains a new
project-root path or a new stored location.

## Source Contracts Against Split Modules

Several services and controllers are split into part files under a sibling directory named after
the module, with the original file kept as the module entry point. A source
contract that reads such a module with `file_get_contents()` sees only the entry
point and silently stops protecting the implementation.

Read the whole module instead:

- in a PHP test, `require_once __DIR__ . '/support/module_source.php';` and call
  `module_source(__DIR__ . '/../app/services/<module>.php')`;
- in `scripts/check_admin_mutation_contracts.php`, `contract_file()` already
  appends part files, so existing contract calls need no change.

In a test that uses bracketed `namespace X { }` blocks, put the `require_once`
inside a namespace block rather than before the first one.

Both helpers append parts in `require_once` order, so assertions that require one
token to appear before another keep their meaning. Both are safe for modules that
were never split, including JavaScript paths, so a shared source helper used for
mixed file types can route every read through them. The current split modules are
listed under "Split Modules" in `ARCHITECTURE.md`.

## Maintenance Center Regression and Manual Verification

`tests/maintenance_center_test.php` is the focused no-database contract for the central Maintenance Center. The central PHP regression suite executes it automatically. It covers registry keys/dependencies, deterministic pipeline order, state-machine transitions, optional deep-media selection, weighted monotonic progress, persistence/lock schema, read-only Analyze boundaries, task/phase selective-module mapping and controller loader injection before callbacks, reviewed dynamic dependency policy, stale-plan revision inputs, one-table-per-step ANALYZE/OPTIMIZE wiring, current eligibility rechecks, cooperative cancellation evidence, automatic site-maintenance lock precedence, independent download/cache operation order and failure isolation, bounded exception classification and raw-message redaction, POST/CSRF/Admin mutation-envelope contracts, route wiring, browser continuation without reload, Dashboard/navigation integration, and Maintenance Center translation-key parity across English, Czech, German, and Swedish.

A real MySQL/MariaDB physical rebuild is environment-dependent and is not proven by the source-level fixture. On a disposable migrated installation, manually verify:

1. Open **Maintenance > Maintenance Center**, start Analyze, and confirm the UI explicitly states that analysis is read-only. Reload during analysis and confirm the same job continues from its persisted task index. Confirm separate analysis/execution requests load the site, log-archive and database maintenance owners without missing-function errors. On a disposable installation with a missing migration ledger, confirm the pending-migration inspection refuses readiness without creating the ledger.
2. Review the ready plan. Confirm deep thumbnail verification is not selected by default and large/protected/unknown database tables are not silently scheduled. Change an application/schema/registry revision on a disposable instance and confirm an old ready plan is rejected as stale.
3. Start maintenance, reload during normal cleanup, close/reopen the Admin page, and confirm progress resumes without restarting completed tasks. Open a second tab and confirm it cannot advance the same job concurrently or start a conflicting central mutation job.
4. Trigger ordinary automatic `site_maintenance` while the central job owns its mutation claim and confirm the automatic run yields before telemetry or other mutations. Public read-only gallery traffic should remain available.
5. Request cancellation during bounded cleanup and confirm it takes effect before the next operation without claiming rollback. If cancelling while an `OPTIMIZE TABLE` call is already running, confirm the current atomic statement returns first and cancellation applies before the following table.
6. Force a browser/network interruption immediately after a physical table request. Confirm persisted `atomic_inflight` evidence prevents automatic replay of that same table on the next request and records the outcome as client-visible unknown instead.
7. Complete a run and verify the report separates logical rows removed, files removed, tables analyzed, tables optimized, skips/warnings/errors, and physical DB before/after bytes only when inventory data actually supports the claim.
8. Verify the Admin Dashboard card shows idle, resumable/running, and last-completed states correctly, including a central job owned by another administrator.
9. Make the legacy download-artifact cache unavailable on a disposable host. Confirm that its step records a bounded warning without a raw exception or private path, preserves completed manifest cleanup, continues to generated-ZIP cleanup, and does not fail the central job.

Use `php scripts/audit.php --profile=full` for an ordinary source handoff or exactly one `php scripts/audit.php --profile=release` for release qualification. Run the focused test directly only while developing it or diagnosing a central-audit failure.

## Test Layers

### 1. Syntax Checks
Syntax checks are owned by the central audit during normal agent verification. For targeted diagnosis of a parser failure, a single file can still be checked directly:

```bash
php -l path/to/file.php
```

The full profile performs repository PHP and JavaScript syntax validation automatically. Do not duplicate that work with a second manual tree walk after the audit.

### Version 0.97 recoverable gallery trash

`tests/gallery_trash_model_test.php` is the focused Version 0.97 contract. It verifies default and bounded settings, independent automatic-purge opt-in, token/path validation, retention countdowns, positive optional-column discovery, disjoint live/trash roots, real nested filesystem moves, verified cross-filesystem copy fallback, schema preflight ordering, guarded rollback, bounded maintenance purge, route authentication/POST/CSRF ownership, canonical Admin mutation envelopes, both ordered migrations, and maintained-language keys. The central PHP regression suite also retains mutation-schema policy, migration consistency, dashboard deferral, Admin side-panel, cache-revision, translation, deployment-policy, and function-documentation coverage.

For manual release acceptance on a disposable migrated installation, delete one gallery with nested subgalleries, originals, generated thumbnails, branding/sidecars, translated metadata, and tags from each supported Admin delete surface. Confirm it immediately disappears from public discovery, appears under **Maintenance > Trash**, and restores to the original hierarchy with metadata intact. Confirm restoration refuses an occupied original path without overwriting live content. Permanently delete one entry, then empty more entries than the configured cleanup batch and verify the browser continues bounded requests while the Maintenance panel and browser URL stay in place. Disable Trash and confirm only future gallery deletes use the legacy permanent path while existing trash remains accessible. Enable automatic purge after a paused period and confirm existing recoverable entries receive a fresh retention window; exercise scheduled reconciliation and purge only on a disposable fixture. Repeat with missing and unknown trash schema states and confirm enabled recoverable deletion stops before filesystem or database mutation. Individual-photo deletion must remain permanently destructive and outside this gallery-trash workflow.

### Version 0.96 recursive gallery migration

Run these checks after changing gallery migration manifests, API routes, package planning, target-tree creation, resume state, the Admin migration form, translations, or browser orchestration:

```text
php tests/gallery_migration_model_test.php
php tests/mutation_schema_policy_test.php
php tests/stage3_auxiliary_mutation_contract_test.php
php tests/translation_catalog_consistency_test.php
node --check public/assets/gallery-modules/admin-gallery-migration.js
php scripts/audit.php --profile=full
```

The focused model test exercises production helpers for legacy and recursive manifest normalization, parent-first tree validation, cross-gallery asset identity, atomic original/thumbnail grouping, deterministic receiver-sized package plans, soft and hard byte limits, package JSON validation, and malformed-tree rejection. The full suite retains route, feature-flag, SEO guard, schema policy, localization, function-documentation, cache-revision, and manifest integration coverage.

For manual verification, use two same-version disposable installations and gallery-scoped API keys. Exercise both push and pull with **Include subgalleries** enabled and disabled. Confirm the imported root is created as a child of the selected receiving gallery, descendants retain their hierarchy and supported metadata, and the selected parent is neither overwritten nor moved. Include originals, multiple existing thumbnail formats, gallery branding, translations, and flight-map data. Interrupt a package request, reload, and verify target status skips installed asset keys before retrying. Confirm completion is refused with a missing asset and succeeds only after every package is present. Repeat with mismatched versions, wrong-gallery keys, malformed package membership, checksum mismatch, path-like ZIP names, an unavailable schema inspection, and a package above the receiving upload limit; each refusal must occur without unauthorized target mutation. Revoke temporary API keys after testing.

The Stage 1 through Stage 6 download sections below preserve the acceptance criteria that applied immediately after each incremental stage. Later stages intentionally supersede some earlier compatibility expectations, for example Stage 4 removes Stage 2 tokenless manifest/source access and legacy capability issuance from the progressive start response. For the current post-Stage-7 release, execute the Stage 7 checklist plus the retained invariants referenced there; use earlier sections when bisecting or validating a rollback to that specific stage.

### Public download hardening: Stage 1

Stage 1 keeps the existing download contract unchanged while adding operational diagnostics and crawler hygiene. Before moving to the capability stages, verify all of the following against the deployed build:

1. A normal physical-gallery Download click still opens the progressive browser dialog, fetches `download_gallery_manifest`, streams source files, and produces the ZIP locally.
2. Direct navigation to `index.php?page=download_gallery&id=<public-gallery-id>` still reaches the bounded no-JavaScript/server-ZIP fallback. Repeat the equivalent Smart Gallery checks.
3. Public download anchors render both `rel="nofollow"` and the `download` attribute while retaining `data-gallery-download` and `data-gallery-download-manifest-url`.
4. All physical and Smart Gallery legacy/manifest/source download responses include `X-Robots-Tag: noindex, nofollow`. A normal `gallery` or `smart_gallery` page must not receive that header from this policy.
5. `robots.txt` explicitly disallows all six download route names in query-string routing.
6. Force one controlled legacy preparation failure in a local/test gallery and inspect the Admin log. The event must contain the resource ID, exception class, sanitized exception message, request method, route, request ID when available, `download_mode=legacy`, stable failure stage/reason, bounded User-Agent, Referer without query/fragment data, and a keyed client-IP fingerprint rather than a raw address. SQL, stack traces, filesystem paths, cookies, share/access tokens, and arbitrary headers must not appear.

Finish with `php -l` for every changed PHP file and `php scripts/generate_manifest.php --check` after regenerating `app/core-manifest.json`. The deployment ZIP normally omits `tests/`, so absence of the source test tree in a production deployment package is expected and must not be "fixed" by adding ad-hoc runtime test files.

### Public download hardening: Stage 2

Stage 2 adds optional stateless HMAC download capabilities while preserving every tokenless Stage 1 route. Verify both paths because the capability path is intentionally parallel, not mandatory yet:

1. Request `index.php?page=download_gallery_start&id=<public-gallery-id>` and confirm the JSON response contains `ok=true`, a `progressive` capability, a separate legacy capability, expiry metadata, and capability-bearing manifest/legacy URLs. No ZIP artifact should be created by this request.
2. Open the returned physical-gallery manifest URL. Confirm it succeeds, every returned source URL carries the same progressive capability, and the browser ZIP can be assembled normally. Repeat with a public downloadable Smart Gallery using `download_smart_gallery_start`.
3. Change one character in a capability, use it with another gallery ID, use a physical-gallery token on a Smart Gallery route, and use a progressive token on the legacy route. Each request must fail cheaply with HTTP 403 before manifest traversal or ZIP building.
4. Verify expiry by issuing a test token with the service-level test harness or by temporarily shortening `runtime_limits['download.capability_ttl_seconds']` in a local config. Expired capabilities must return 403. Oversized/malformed capabilities must also be rejected before JSON/resource work.
5. Re-run the original tokenless `download_gallery_manifest`, `download_gallery_file`, `download_gallery`, and all Smart Gallery equivalents. They must behave exactly as Stage 1 did. This is the Stage 2 rollback/compatibility invariant.
6. Confirm `download_gallery_start` and `download_smart_gallery_start` are behind the existing Downloads feature flag, receive `X-Robots-Tag: noindex, nofollow`, and are listed in `robots.txt`.
7. Confirm existing `config.php` files need no edits. Capability signing must derive a purpose-specific key from the existing stable application secret unless a dedicated `download_security.capability_secret` override is explicitly configured.
8. Review `app/configuration_defaults.php` as the canonical source for download/browser-upload operational limits. Feature modules must consume these values through `cms_runtime_limit()` rather than redeclaring numeric policy constants.

Finish with PHP syntax checks for every changed PHP file, the focused capability regression script from the source test tree when available, and `php scripts/generate_manifest.php --check`.

### Public download hardening: Stage 3

Stage 3 moves the normal JavaScript downloader to header-transport capabilities while keeping the bounded query transport only for compatibility. Verify:

1. Click Download on a physical gallery and a downloadable Smart Gallery. The browser must POST to the corresponding `*_start` route before any manifest/source request and must not navigate away from the page.
2. In Network, confirm normal manifest and source URLs contain no `capability=` query parameter. Requests instead carry `X-PHP-Gallery-Download-Capability`, responses are `private, no-store`, and `Referrer-Policy: no-referrer` is present.
3. Remove the header, alter the token, use the wrong scope/resource type/ID, or let the token expire. Manifest/source requests must return 403 before bounded enumeration/source resolution.
4. Exercise the documented query-token compatibility path manually and confirm it remains resource/scoped, bounded, and noindex. Supplying different header and query tokens must fail.
5. Confirm the browser still validates source `Content-Length` against manifest `size`, handles source-version 409 by failing/retrying the download rather than silently creating a corrupt ZIP, and preserves ZIP64 behavior for large local archives.

### Public download hardening: Stage 4

Stage 4 makes every crawler-reachable legacy GET/HEAD route cheap and non-building. Verify:

1. `GET` and `HEAD` for `download_gallery?id=<id>` and `download_smart_gallery?id=<id>` may render/describe the explicit fallback confirmation, but must not enumerate a manifest, create a partial ZIP, or change the immutable artifact/cache tree.
2. The public hero control is an explicit POST form containing resource ID plus a valid `legacy` capability. With JavaScript enabled, progressive enhancement intercepts it and uses the progressive start handshake. With JavaScript disabled, POST submits the bounded legacy fallback.
3. A POST without capability, with malformed/expired capability, wrong resource ID/type, or a `progressive` token must return 403 before manifest enumeration or ZIP building.
4. Confirm large legacy requests still stop at configured file/byte caps with a controlled response. Normal progressive browser downloads are not subject to the smaller legacy fallback cap.
5. Confirm no normal public page contains a crawlable URL whose GET alone can trigger server ZIP construction.

### Public download hardening: Stage 5

Stage 5 adds canonical single-flight and global build admission around the remaining deliberate legacy build path. Verify with two or more parallel POST requests against an uncached small gallery:

1. Only one request owns the canonical build lock. A duplicate build receives HTTP 503 plus bounded `Retry-After`, or reuses the completed result if publication wins the race.
2. Start builds for different resources until `download.legacy_max_concurrent_builds` is reached. Further uncached builds must return 503 without waiting indefinitely or beginning ZIP work.
3. A completed cache/artifact hit must not consume a global build slot and transfer speed must not be throttled.
4. Changing only capability nonce, request ID, User-Agent, host, or irrelevant query parameters must not change the canonical build key. A true gallery/result revision must change it.
5. Kill a local build process while it owns a lock and retry. Kernel `flock()` release must allow recovery without manual lease deletion; incomplete partial output must never be served.

### Public download hardening: Stage 6

Stage 6 makes completed legacy fallback ZIPs immutable managed artifacts. Verify:

1. First authorized legacy POST builds and atomically publishes one artifact. The next POST for unchanged content reuses it without invoking ZIP construction again.
2. Mutate a physical gallery or change the canonical Smart Gallery result. The new revision/fingerprint must use a distinct artifact identity; the old artifact remains isolated until maintenance eligibility.
3. Inspect `metadata.json` in a local cache fixture. It may contain bounded artifact identity, timestamps, size, and expected count, but never capability tokens, visitor/client identity, public URLs, or source paths.
4. Simulate interrupted publication. `.partial-*` content must not be returned by artifact lookup/serve paths.
5. Run maintenance while one artifact is served/built under its lease. Active content must be skipped. Eligible expired artifacts, abandoned partials, and stale inactive coordination state may be removed only from managed cache roots.
6. Temporarily lower `download.legacy_artifact_cache_max_bytes` or raise the free-space margin enough to refuse a new build. Confirm HTTP 507, no partial published artifact, and an Admin event `download.legacy_cache_capacity_refused`.
7. Restore production limits and confirm physical default retention is seven days, Smart Gallery retention one day, partial retention six hours, and inactive coordination retention 24 hours unless locally overridden.

### Public download hardening: Stage 7

Stage 7 reduces repeated progressive-manifest filesystem cost without weakening current authorization. Verify both physical and Smart Gallery routes:

1. Use an Admin test run or browser/devtools timing against a small, medium, and large gallery. On the first authorized request inspect the `download_manifest_profile` component, `Server-Timing`, and `X-PHP-Gallery-Manifest-Cache: miss`. Record SQL query delta when Admin test instrumentation is active, gallery/image row counts, filesystem checks/size reads/realpath checks, elapsed time, and memory. Repeat immediately and expect `hit` with the per-source filesystem work absent while normal authorization/query work remains.
2. Repeat the exact request with irrelevant parameters such as `&noise=1`, a different request ID, or a newly issued capability for the same content. It must resolve to the same revision-keyed cache entry. Do not use arbitrary query parameters that the dispatcher itself rejects as a proxy for cache identity; verify the cache header and on-disk revision filename instead.
3. Inspect one cached `.download-manifests/<type>/<id>/<revision>.json` fixture. It may contain only format/resource/revision/timestamp plus normalized `name`, `size`, `image_id`, `version` data and totals. Search the file for the active capability token and confirm it is absent.
4. Change gallery/image metadata that affects the physical content revision, or change Smart Gallery rules so the ordered result changes. The next manifest must miss the old identity and produce current metadata. Changing only title/host/client/query data that does not affect downloaded file content must not create a new revision entry.
5. While a metadata cache entry exists, revoke access or make a source image/gallery non-public. A request that is no longer authorized must fail before the cache can grant content. Smart Gallery membership must be recomputed before cache reuse. Capability validation remains mandatory on every protected manifest/source request.
6. Request one manifest/source with POST or HEAD, malformed/oversized/non-decimal IDs, array parameters, or malformed source version. Expect 405/400 before DB/filesystem work as applicable. Valid GET follows capability, resource lookup, authorization, revision/cache, bounded work, then transfer ordering.
7. Source-file requests must still independently resolve gallery/Smart Gallery membership, image visibility, containment, size, and optional `v` version. Generated URLs also contain `mr` and `s`; confirm the SEO request guard accepts both parameters on `download_gallery_file` and `download_smart_gallery_file` before dispatch. When the actual size differs, the endpoint must return 409 and invalidate only an exact cache entry that proves the same image/version/expected-size tuple. Tampering with `s` must not evict a valid cache entry. The successful response retains exact `Content-Length`, no intentional bandwidth throttle, and authorized PHP `readfile()` streaming.
8. Exercise the SEO guard with a deliberately unexpected parameter while the same request also contains a fake `capability`, `token`, or `share` value. The sampled Admin security event may record the request path and unexpected parameter names, but its `request_uri` field must replace the entire query string with `?[query-redacted]`; no bearer/query value may appear in the event context.
9. For the no-JavaScript legacy path, mutate a source file size after a manifest has been cached. Before any ZIP build, the server must recompute current aggregate source bytes, invalidate stale manifest metadata on mismatch, and enforce `download.legacy_max_source_bytes` against actual bytes rather than the cached total.
10. Run scheduled/site maintenance and confirm expired/corrupt manifest metadata and old partials are removed within `download.manifest_cache_cleanup_max_entries`; unrelated ZIP/cache/media files must not be touched by this cleanup.
11. Disable JavaScript and verify the explicit Stage 4-6 bounded legacy POST fallback still works for a small physical and Smart Gallery. Re-enable JavaScript and verify both progressive downloads end-to-end, including ZIP creation in the browser.
12. Confirm PHP 8.1 compatibility for changed syntax/APIs, run `php -l` on every changed PHP file, regenerate `app/core-manifest.json`, run `php scripts/generate_manifest.php --check`, and run `php scripts/audit.php --profile=full` when the source test tree is available. The central full profile already owns the registered Node suite. Production deployment ZIPs normally omit `tests/`, so record that absence rather than inventing runtime tests.

Stage 7 runtime defaults are centrally merged and require no existing `config.php` edit: physical manifest metadata retention 86400 seconds, Smart Gallery retention 900 seconds, maximum single metadata file 16777216 bytes, and bounded cleanup scan 10000 entries. A rollback may delete only the private `.download-manifests` subtree; the next authorized request follows the cache-miss path and the progressive protocol remains unchanged.

### 2. Script-Level Tests
The repository uses current direct PHP regression tests under `tests/`. Run the complete isolated suite with:

```bash
php scripts/audit.php --profile=full
```

The central full profile owns every registered standalone Node model. Do not glob `tests/*_test.mjs` manually: the ZIP writer tests require managed temporary output paths, ZIP64 is classified as slow coverage, and browser integration has a different environment contract. When diagnosing one Node failure directly, use the exact invocation recorded in `scripts/audit_registry.php`. The central runner creates and removes temporary ZIP outputs automatically.

Deployment packages remain production-like and omit `tests/` by default. A normal local ZIP is created with `./deploy.sh --mode local --deploy-folder deploy --upload-media false --make-zip-deploy true`; add `--include-tests true` only for a development/source-review ZIP. On Windows use `scripts/deploy.ps1 -Mode local -DeployFolder deploy -UploadMedia false -MakeZipDeploy true`, adding `-IncludeTests true` for the review package. Test inclusion is local-only and is rejected in FTP mode; all existing secret, runtime-data, cache, log, `.git`, and media exclusions remain in force.

Browser ZIP-import changes must additionally verify stored and Deflate archives, nested image paths, mixed supported and unsupported entries, empty archives, damaged central directories, encrypted/ZIP64 archives, traversal names, hidden `__MACOSX` metadata, oversized entries, expansion-ratio limits, duplicate filenames, and a ZIP selected while browser-assisted upload is unchecked or unsupported. The archive itself must never reach the classic PHP upload request; extracted valid images must still use the existing browser preparation, batching, server validation, thumbnail, and Admin side-panel progress pipeline.

For Windows upload-automation regression testing, exercise both the watched-folder screenshot path and manual WinApp upload. The canonical multipart request must use `X-Gallery-API-Key`, `images[]`, `create_thumbnails`, optional `image_client_ids[]`, and optional `client_thumbnails[]` metadata. With local thumbnail generation available, verify the client can submit the full JPG+WebP compatibility matrix against both thumbnail modes: a modern WebP-only server must accept the original and WebP variants while counting valid JPG extras as skipped without returning an upload error or triggering watcher fallback to server-side thumbnail generation; a legacy server must accept both formats. Keep invalid/unknown formats, MIME mismatches, unsupported sizes, and malformed thumbnail uploads as hard request failures. Watched screenshots should also preserve optional Flight Simulator camera metadata and inventory-based duplicate suppression.

For create-with-upload regression testing, also create a new child gallery with one or more photos while browser-assisted upload is enabled. Confirm exactly one gallery row/folder/card is created and each selected photo is stored once. The successful browser result contains canonical `fallback` metadata as an object; this must not cause the classic upload path to run afterward. With selected photos and browser processing checked, deliberately break one required browser capability or worker preparation step and confirm the upload stops before persistence instead of switching to classic PHP upload. A browser batch with thumbnail creation enabled must carry `prepared_thumbnails_required=1`, and PHP must reject an incomplete prepared-thumbnail manifest before storing originals. The literal `fallback === true` path is reserved for an empty create-gallery submission with no files. Run `php scripts/check_admin_mutation_contracts.php` to protect these invariants. Repeat once with browser preparation disabled to confirm the classic path still creates exactly one gallery and remains the explicit server-side choice.

Run `node tests/browser_upload_zip_worker_test.mjs` for the generated stored/Deflate mixed-archive worker fixture. Node is a development-only test convenience; it is not required by the deployed PHP application.

Run one focused test directly when diagnosing a failure:

```bash
php tests/gallery_visibility_model_test.php
php tests/duplicate_photo_detector_test.php
php tests/duplicate_photo_ledger_test.php
php tests/browser_upload_settings_test.php
php tests/gallery_public_paths_test.php
php tests/migration_consistency_test.php
php tests/migration_legacy_runner_compatibility_test.php
php tests/database_maintenance_test.php
php tests/database_maintenance_schema_repair_test.php
php tests/updater_safety_model_test.php
php tests/updater_resumable_state_machine_test.php
php tests/thumbnail_warmup_model_test.php
php tests/public_thumbnail_rendering_model_test.php
php tests/public_thumbnail_markup_test.php
php tests/hero_tag_theme_model_test.php
php tests/tag_metadata_mysql_compatibility_test.php
php tests/translation_catalog_consistency_test.php
php tests/lightbox_zoom_lifecycle_test.php
php tests/lightbox_zoom_integration_test.php
php tests/lightbox_zoom_translation_test.php
php tests/lightbox_zoom_quality_candidates_test.php
php tests/lightbox_zoom_quality_rendering_test.php
php tests/lightbox_zoom_quality_lifecycle_test.php
php tests/lightbox_zoom_quality_indicator_test.php
node tests/lightbox_zoom_model_test.mjs
node tests/progressive_thumbnail_renderer_test.mjs
```

The favorite shortcut test covers zero configured shortcuts, direct gallery links, the optional main-page shortcut, duplicate/missing-gallery cleanup, public visibility filtering, and HTML escaping.
The gallery dates test covers manual date range normalization, reversed-range rejection, public display formatting with en dash separators, rendered date attributes, and branch matching used by scoped EXIF suggestion reviews.
The duplicate photo detector tests cover exact checksum matches, normalized EXIF candidates, file-size-only rejection, selected-branch/global scope, deterministic and bounded pair expansion, persistent pair/exact-gallery filtering, parent/child gallery independence, clickable public context links, delete and ledger scope validation, detector-job pruning, database migration contracts, reuse of the existing image deletion service, and in-place AJAX side-panel integration for delete/ignore/clear actions. The ledger test separately covers canonical pair storage, per-administrator keys, exact-gallery semantics, cascade constraints, current-admin clearing, and protected maintenance policy.
The gallery public-path test covers Czech transliteration, decomposed accents, invisible Unicode characters, HTML entities, hierarchical paths, and sibling slug collisions.
The tag metadata MySQL compatibility test guards the Admin tag-usage query against MySQL error 3065 by requiring every DISTINCT ordering expression that is not already projected to be included in the SELECT list.
The migration consistency test validates every migration definition, preflights the complete migration set, and proves that old schema_migrations rows remain harmless after obsolete migration files are removed.
The legacy migration-runner compatibility test verifies that PHP repair migrations work both with the current definition-aware runner and with the former SQL-only runner that may still be present during a partial patch deployment.
The database maintenance test covers information_schema normalization, compact and legacy schema detection, SQL-literal reference scoping, obsolete thumbnail objects, orphan and expiry rules, deterministic duplicate survivor selection, protected content/log/telemetry tables, report-only unsupported thumbnail variants, Admin authentication, CSRF, confirmation contracts, and the absence of filesystem cleanup side effects.
The Admin log scaling test covers indexed age/grouping migration contracts, grouped browsing, bounded keyset exports, retention normalization, and the archive-first deletion boundary. The Admin log archive maintenance test covers protected day archive paths, self-describing JSON/HTML output, row-count verification, interrupted-work recovery, resumable state, and retention cleanup without exposing archive data publicly.
The database maintenance schema-repair test uses a mutable PDO fixture to verify audit-table creation, absent thumbnail tables, partially compacted schemas, geometry migration before destructive DDL, obsolete index/foreign-key cleanup, already compact schemas, idempotent retry, and the absence of row or filesystem deletion.
The updater safety test verifies that critical runtime files, the core manifest, and the resumable update service are required before deployment starts and that valid top-level app entries such as `app/views.php`, `app/views/`, `app/lang/`, and migration support modules are never classified as misplaced project copies. `tests/updater_resumable_state_machine_test.php` additionally covers complete atomic checkpoint replacement, staging cleanup and destination preservation on refusal (including Windows read-only checkpoint protection), ordered stage transitions, bounded time budgets, package-path rejection, manifest coverage, corrupt-archive rejection, safe error redaction, worker locking, stale-lock recovery, rollback snapshot copying, activation ordering, stable/beta/reinstall/restore job routing, background continuation, migration checkpoint wiring, Admin in-place controls, side-panel event delegation, browser reopen continuation, and the absence of page reload/navigation in the JavaScript updater.

The translation catalog consistency test requires English, Czech, German, and Swedish to remain key-for-key complete, verifies placeholder parity across all four maintained catalogs, statically checks that literal PHP/JavaScript translation calls exist in English, validates dormant future-language skeletons as safe subsets of English, confirms that only `en`, `cs`, `de`, and `sv` are selectable in `config.example.php`, and guards the Admin/Public selector filtering plus the English default/fallback contract.

The public thumbnail rendering model test covers progressive default/fallback normalization, supported setting persistence, invalid Admin input normalization, the narrow renderer dispatch boundary, the unchanged responsive eager/lazy/fetchpriority thresholds, and progressive small-thumbnail thresholds. The public thumbnail markup test covers complete responsive srcsets, small-only progressive active srcsets, inert larger candidates, WebP/JPEG structures, missing variants, synthetic bounds, intrinsic dimensions, media fallback, warm-up attributes, and selected-gallery NSFW gate ordering. The hero tag Theme model test covers 20-tag and five-row defaults, server-side clamping, display-all and scrollbar booleans, usage/alphabetical mode normalization, Admin persistence wiring, complete server-rendered hero groups, full-width CSS overrides, anonymous/logged-in browser entrypoints, accessible disclosure state, row-based scrollbar activation, and English/Czech public strings. `tests/progressive_thumbnail_renderer_test.mjs` covers browser-independent candidate parsing, smallest-adequate selection, capped DPR width calculation, queue deduplication, visible priority, and the two-worker concurrency bound. DOM intersection, actual browser network order, decode timing, cache reuse, lightbox/maps/votes interaction, hero tag wrapping at real browser widths, and reduced-motion rendering remain manual checks.

These tests are maintained against the current namespaced production code. They are best for pure logic, helper functions, and regression checks that do not require a browser session. A release patch should not be published while `php scripts/audit.php --profile=full` reports a failure.

### Viewer Phase 0 security-foundation coverage

Phase 0 viewer foundations deliberately have no HTTP route, so focused tests exercise services and static architecture boundaries without requiring an external mail provider, browser, or Internet service:

```text
php tests/viewer_security_foundations_test.php
php tests/viewer_schema_foundations_test.php
php tests/viewer_identity_boundary_test.php
```

`viewer_security_foundations_test.php` verifies disabled-by-default/fail-closed configuration, service-level refusal before database access while disabled, independent viewer session namespace behavior, preservation of administrator session keys, cryptographically random opaque tokens, authority hashing/verification, deterministic email normalization, native password hash/verify/rehash behavior, no silent bcrypt-length truncation, one-time-token expiry/consumption policy, fixed abuse-policy names, identifier/subnet normalization, configured hard subject caps, trusted-proxy CIDR behavior, default rejection of spoofed forwarding headers, and security-event context redaction.

`viewer_schema_foundations_test.php` validates that the migrations are additive and ordered, creates every intended viewer table including the Phase 0.6 durable-account counter, leaves historical identity/media tables untouched, stores token authority hashed, defines expiry/invalidation/single-use lifecycle fields, uses canonical `images.id` references, protects favourite/collection uniqueness with database constraints, provides deterministic collection ordering, keeps collection/share rows free of gallery/media permission fields, stores no passkey private key, uses bounded rate-limit storage, defaults the feature and registration off, defaults trusted proxies off, exposes no viewer controller/dispatcher route, and verifies scheduled viewer cleanup is no longer gated by feature enablement.

`viewer_identity_boundary_test.php` guards the most important repository-specific security invariant: `current_user()` continues to use only the administrator `users` table and `$_SESSION['user_id']`, while `current_viewer()` uses only viewer session/tables. It also proves the historical `visitor_can_access_gallery()` administrator bypass still depends only on `current_user()`, public media does not consult viewer auth, existing administrator auth/persistent-login code remains viewer-unaware, the existing CSRF contract is unchanged, and historical gallery share-token validation remains separate from future collection sharing.

These focused tests supplement, rather than replace, `php scripts/audit.php --profile=full`, `tests/migration_consistency_test.php`, authentication schema-policy tests, gallery-access schema-policy tests, and the Node model tests. Fresh installation and upgrade safety are represented by the shared migration directory/runner contract plus migration preflight/replay tests. When a disposable MySQL/MariaDB instance is available, release qualification should additionally execute a fresh install and an upgrade from a pre-Phase-0 database because MySQL DDL cannot be rolled back as one transaction.

### Viewer Phase 0.5 registration/mail-abuse foundation coverage

Phase 0.5 remains route-free and transport-free. Run:

```text
php tests/viewer_registration_foundations_test.php
php tests/viewer_mail_abuse_foundations_test.php
```

`viewer_registration_foundations_test.php` verifies disabled/fail-closed staging, generic public-result foundations, optional invitation email binding, claimed/revoked/expired invitation rejection, transactional invitation-state revalidation after preflight, revocation availability while admission is disabled, scanner-safe non-consuming verification predicates, replay rejection, three-state schema availability, binary-unique pending-email deduplication, unique invitation use, hashed invitation/verification authority, indexed expiry cleanup, the locked registration-capacity counter, absence of password storage in staging, and Phase 4.1 reuse of the same registration service rather than a parallel signup subsystem.

`viewer_mail_abuse_foundations_test.php` verifies independent verification/reset/invitation mail budget plans, per-address cooldown/hour/day limits, per-client and installation-wide limits, narrow-to-global reservation ordering that protects the global circuit breaker from suppressed-request exhaustion, generic future external outcomes, fail-closed invalid recipient/client handling, corrected `max_attempts` semantics, and the deliberate absence of PHP `mail()`, SMTP sockets, provider APIs, queues, or other delivery code. Existing administrator password-reset mail is not refactored by Phase 0.5.

These tests intentionally do not claim live transactional concurrency coverage because the standard sandbox/repository test environment may not provide a PDO MySQL/MariaDB driver. The SQL paths use row locks, unique constraints, and transaction-scoped capacity admission. Release qualification on a disposable MySQL/MariaDB database should additionally race duplicate pending requests, one invitation claim, and one verification confirmation to confirm exactly one durable state transition.

### Viewer Phase 0.6 authentication/request-security foundation coverage

Phase 0.6 remains route-free, UI-free, cookie-emission-free, and mail-transport-free. Run:

```text
php tests/viewer_authentication_phase06_test.php
```

`request_https_proxy_test.php` covers shared Core/Viewer transport for direct HTTP/TLS, disabled TLS indicators, trusted IPv4/IPv6 proxies, separate header opt-in, invalid peers/configuration and conflicting/multiple protocol values. It verifies configured/inferred public URL schemes and actual Admin session cookie parameters using disposable session storage. It runs in the central quick/full PHP suites without a database or changes to installation configuration. Live proxy qualification must also verify that the proxy overwrites enabled incoming protocol headers and its direct peer matches the configured trust scope.

`viewer_authentication_phase06_test.php` exercises the aggregate three-state viewer-auth schema capability (available/missing/unknown), 15-code-point native password policy, Unicode/spaces/no-composition behavior, native hash/verify/rehash, viewer/admin CSRF separation, short-lived activation and reset pre-auth namespaces, activation-state HMAC binding/expiry, forged versus trusted forwarded HTTPS, invalid proxy config, IPv4/IPv6 trusted-proxy behavior, strict configured security-link origin and Host-header poisoning resistance.

The same test statically protects database transaction/locking contracts that cannot be executed without PDO MySQL: singleton `viewer_account_state` serialization, invitation re-lock during activation, staging retirement, login throttle ordering before account/password work, deterministic session/remember caps, remember selector/verifier rotation, security-version-aware reset locking/revocation, collection-share revocation on account state changes, viewer/pre-auth no-store classification, cleanup while disabled, and the Phase 0.6 service boundary remaining free of direct cookie emission and mail transport. Phase 1.0 HTTP adapters may now consume those established services.

A release environment with MySQL/MariaDB and `pdo_mysql` should additionally run live races for durable account-cap admission, two concurrent activations, session-cap admission, remember restore rotation, reset-token final use, and security-version invalidation. If that database capability is absent, release notes/test reports must state those integration races were **not run** rather than presenting static/model checks as live concurrency coverage.

### Viewer Phase 0.7 lifecycle/content-authorization coverage

Phase 0.7 is still HTTP-free and UI-free. Run the focused deterministic contract test with:

```text
php tests/viewer_account_lifecycle_phase07_test.php
```

It covers three-state lifecycle schema capability, recent-reauth namespace clearing/expiry, interactive-login versus remember-restoration semantics, strict future content-quota parsing, 120-code-point/480-byte plain-text title policy, invalid UTF-8/NUL/control/bidi rejection, scanner-safe staged email change, security-version-aware password/email mutation structure, account-deletion capacity reconciliation, no-admin-bypass source-image authorization, and the continued absence of Phase 0.7 lifecycle HTTP wiring while keeping the Phase 0.7 content-foundation service policy-only; later favourite CRUD is covered separately by the Phase 1.1 test and private collection CRUD is covered separately by the Phase 2.0 test.

A real MySQL/MariaDB race harness is optional and intentionally separate from the default suite:

```text
GALLERY_TEST_MYSQL_DSN='mysql:host=127.0.0.1;dbname=php_gallery_test;charset=utf8mb4' \
GALLERY_TEST_MYSQL_USER='gallery_test' \
GALLERY_TEST_MYSQL_PASSWORD='...' \
php tests/viewer_phase07_mysql_concurrency_test.php
```

The database must already contain the current migrated schema and must be disposable test data. The harness creates isolated fixture rows, launches independent PHP worker processes with independent PDO connections, releases them through an explicit process-pipe barrier, and cleans up/reconciles viewer capacity state afterwards. It exercises seven storage-level race invariants separately: duplicate verified activation, hard durable-account cap, active session cap, remember-token rotation, one reset-token final use, security-version invalidation competing with authentication authority, and account deletion versus account-capacity consistency. It is a low-level InnoDB/row-lock integration harness, not a replacement for service-contract tests.

If `pdo_mysql`, the DSN, or the required migrated tables are unavailable, the harness prints `SKIP` with the exact reason and exits without claiming that a race ran. Static SQL inspection is not equivalent to this live test.

### Schema-inspection reliability regression coverage

The schema-inspection reliability conversion is complete. Repository-wide review
should still search for `SHOW COLUMNS`, `SHOW TABLES`, `information_schema`,
`db_column_exists`, `db_table_exists`, `schema_ready`, `column_exists`, and
`table_exists` when adding or modifying schema-sensitive code. A direct metadata
query is acceptable only when its purpose cannot be represented as inspection of a
known table/column/index/definition and the exception is documented and tested.

Behavioral coverage must preserve all three inspection states:

- successful inspection with the object available;
- successful inspection with the object missing;
- failed inspection with state unknown.

Tests must prove that unknown state cannot become a permissive access default,
an unthrottled authentication path, an accepted upload, a partial destructive
mutation, or a misleading “migration missing” diagnostic. Presentation-only
fallback tests should prove that the base page can continue only when omitting
the optional feature cannot reveal protected content or authorize a write.
Migration tests must also prove that request-local inspection state is reset
after successful DDL when inspection and validation happen in one PHP process.

The Phase 2 primitive test is available now:

```text
php tests/schema_inspection_model_test.php
```

It covers table, column, and index availability and absence; generic and PDO
inspection failures; safe SQLSTATE handling; secret and hostname redaction;
identifier rejection before query execution; request-local cache reuse and
reset; state predicates; feature requirement preservation; and
`unknown > missing > available` aggregation. The dedicated Phase 3 hardening
also verifies the production `information_schema` query definitions and bound
parameters, `DATABASE()` scoping, registration before consumers, independent
cache identities, cached missing/unknown results, executor-triggered cache
reset, mutually exclusive predicates, private-path/token redaction, and
rejection of incomplete aggregate requirements. It uses a narrow executor seam
and does not connect to a live database.

The first security-sensitive caller test is available now:

```text
php tests/nsfw_schema_policy_test.php
```

It verifies unchanged gallery-level and image-level NSFW enforcement with a
complete schema, the documented historical compatibility path for confirmed
pre-feature schemas, and fail-closed behavior for unknown inspection state. An
isolated dispatcher fixture runs the real dispatcher and 503 response helper to
prove that unknown state blocks media, thumbnails, lazy lightbox metadata, and
map metadata before their handlers emit output; returns translated HTML, JSON,
or plain text with status 503 and a safe request reference; and never exposes
fixture SQL or secrets. The same test distinguishes missing from unknown Admin
health, proves unknown never becomes disabled, verifies logged-in anonymous
preview follows the same safe public policy, preserves complete-schema route
behavior, and protects the explicit bulk-mutation refusal contract. It uses the
isolated schema query executor and does not connect to a live database.

The response boundary has an additional focused test:

```text
php tests/service_unavailable_response_test.php
```

It renders the real response helper with isolated translation, request-ID, and
Admin-log doubles. The test verifies HTTP 503 status; HTML, JSON, and plain-text
bodies; stable public and internal error codes; request correlation; bounded
security log context; absence of representative SQL, credential, token, stack,
and private-path values; and the no-store, retry, crawler, and content-type
header contract. It requires no database or web server.

Admin health interpretation has a focused pure-model test:

```text
php tests/admin_nsfw_system_health_test.php
```

It covers available, confirmed missing, unknown, intentionally disabled, and
malformed states; request-reference ownership; migration and operational
suggested-check keys; validated affected-object identities; rejection of raw
diagnostics and malformed object names; dashboard Maintenance/System Health
action badges; and shared Runtime Diagnostics ownership. The disabled case
protects the common health vocabulary. NSFW Guard itself currently has no
configuration feature flag and therefore does not produce disabled in normal
runtime operation.

Phase 8 adds migration/cache integration coverage:

```text
php tests/migration_schema_cache_reset_test.php
```

The test uses a minimal PDO double plus the schema-inspection executor seam, so
it needs no live MySQL or MariaDB service. It first proves that a cached
`missing` result is reused during the request and remains cached across a
data-only migration statement. It then executes the real
`apply_migration_statement()` boundary with simulated DDL and proves that the
next capability check performs a fresh inspection and sees the changed schema.
The duplicate-DDL replay path is covered as well because interrupted shared-host
updates can encounter objects that already exist. Source contracts additionally
protect cache invalidation after the `schema_migrations` bootstrap and after
successful migration repair callbacks, which may perform their own DDL.

`tests/nsfw_schema_policy_test.php` also counts the pilot's metadata calls. A
complete NSFW capability requires exactly one request-local lookup for
`galleries.nsfw_enabled` and one for `images.nsfw_enabled`; repeated readiness,
gallery, and image policy helpers must not add more `information_schema`
queries in the same request.

For Phase 8 pilot review, run the focused set before the full suite:

```text
php tests/schema_inspection_model_test.php
php tests/nsfw_schema_policy_test.php
php tests/service_unavailable_response_test.php
php tests/admin_nsfw_system_health_test.php
php tests/migration_schema_cache_reset_test.php
php tests/migration_consistency_test.php
php scripts/audit.php --profile=full
```

Manual NSFW outage verification should simulate an inspection failure on a
disposable installation. Confirm that gallery pages receive translated 503
HTML, lightbox/map/search requests receive 503 JSON, media and thumbnail routes
receive 503 plain text, no protected URL or metadata appears, and Admin System
Health shows an inspection failure with a safe reference. Confirm that gallery,
image, and bulk NSFW changes are refused, then restore database access and
verify existing restrictions behave unchanged.

### Phase 9 security and authentication schema policy tests

Phase 9 adds three focused test scripts plus an isolated dispatcher fixture:

```text
php tests/gallery_access_schema_policy_test.php
php tests/auth_schema_policy_test.php
php tests/security_schema_system_health_test.php
```

`tests/gallery_access_schema_policy_test.php` covers the public authorization
side of the conversion. It verifies:

- complete gallery-access schema preserves password inheritance and unlisted
  behavior;
- confirmed legacy compatibility is permitted only when all five core access
  columns are absent;
- a partially applied access migration fails closed instead of substituting
  `normal`/`listed`;
- access metadata inspection failure remains `unknown` and redacts simulated SQL
  and credential material;
- confirmed historical visibility vocabulary stores canonical `unpublished` as
  `draft`, while unknown enum inspection refuses to guess;
- missing share-token display storage disables token use, while unknown storage
  raises the dedicated policy exception;
- the real dispatcher returns 503 before gallery, public-media, public-thumbnail,
  lazy-lightbox-data, and gallery-download sentinel handlers for partial access,
  unknown access, and unknown visibility states;
- response representation remains HTML, JSON, or plain text according to route;
- bounded logs contain only the feature, state, route, response format, and
  request correlation, never fixture secrets, DSNs, passwords, or SQL.

`tests/support/security_schema_policy_dispatch_fixture.php` provides the isolated
real-dispatcher environment for those route tests. Keep it aligned with the
central sensitive-route preflight in `app/bootstrap/dispatch.php` whenever a new
public endpoint can expose protected gallery state, metadata, archives, or media.

`tests/auth_schema_policy_test.php` covers authentication capabilities without a
live database. It verifies:

- `admin_remember_tokens`, `users.email`, `password_reset_tokens`, and
  `user_google_accounts` available/missing/unknown states;
- request-local cache reuse, including one metadata query for `users.email` even
  when both email-login and password-reset status request it;
- confirmed missing remember-token storage degrades to ordinary PHP-session login
  instead of failing authentication;
- unknown remember-token storage refuses persistent-token issuance/use and logs
  only bounded feature/operation context;
- password reset becomes incomplete when either its table or `users.email` is
  missing and remains unknown when the shared email dependency cannot be
  inspected;
- confirmed missing external-identity storage can safely disable read/link UI,
  while unknown storage refuses both lookup and mutation;
- configuration-disabled persistent login short-circuits before metadata queries;
- schema-policy-only checks do not touch application data tables.

`tests/security_schema_system_health_test.php` protects the generic four-state
Admin health model, bounded affected-object normalization, request-reference
behavior, the complete Phase 9 capability registry, the System Health action
badge contract, and Runtime Diagnostics use of the same status set.

For Phase 9 regression work, run the focused security set first:

```text
php tests/schema_inspection_model_test.php
php tests/gallery_access_schema_policy_test.php
php tests/nsfw_schema_policy_test.php
php tests/auth_schema_policy_test.php
php tests/security_schema_system_health_test.php
php tests/admin_nsfw_system_health_test.php
php tests/service_unavailable_response_test.php
php tests/migration_schema_cache_reset_test.php
php tests/translation_catalog_consistency_test.php
php scripts/audit.php --profile=full
```

Manual Phase 9 outage verification should use a disposable installation and
exercise more than the NSFW card. Temporarily deny metadata inspection or make
the selected database unavailable, then confirm: public gallery/media/thumb/
lightbox/download requests fail before protected output; System Health shows the
affected access/visibility/auth capability as unknown; password login does not
turn an inspection failure into an invalid-credential result; persistent login
is not issued; password reset and Google link/login operations report temporary
storage unavailability; an already authenticated PHP session remains usable
where the failed optional capability is not needed. Restore metadata access and
confirm normal behavior without restarting the PHP process when testing through
a same-process migration path.

Manual partial-migration verification should remove or rename one access column
only on a disposable database. Confirm that the installation is not treated as a
fully legacy unprotected gallery. Restore/apply the migration and verify the
existing password, unlisted, share-link, media, and download rules again.

### Phase 10 destructive and ingestion schema policy tests

Phase 10 adds `tests/mutation_schema_policy_test.php` and extends the updater
safety fixture. The focused test is intentionally mixed behavioral/static
coverage because the mutation policy itself is database-independent while many
production mutation functions also require filesystem, HTTP upload, or full Admin
runtime context.

`tests/mutation_schema_policy_test.php` verifies:

- available, confirmed missing, and unknown aggregate state for a destructive
  gallery capability;
- redaction of simulated database connection/SQL/credential details from unknown
  inspection results;
- confirmed missing optional columns return the documented compatibility answer,
  while an unknown optional column raises `MutationSchemaUnavailableException`
  instead of being converted to `false`;
- upload-automation issuance/authentication requires the complete token schema,
  while `upload_automation_revocation_schema_status()` remains available with the
  smaller identity/revocation column set;
- mobile WebDAV issuance/authentication requires the complete credential schema,
  while the independently verified deletion capability can remain available;
- `upload_ingestion_schema_status()` uses exactly twelve metadata probes for its
  complete gallery/image requirement set on first use and zero additional probes
  when repeated in the same request;
- every converted Phase 10 mutation service is free of `db_table_exists()` and
  `db_column_exists()` authorization logic;
- `mutation_schema_policy.php` is loaded before destructive consumers;
- all ten mutation capability keys are registered in Admin System Health and the
  same set is consumed by Runtime Diagnostics;
- classic upload performs thumbnail-write compatibility preflight before
  `move_uploaded_file()` can commit a source to the gallery;
- prepared browser upload performs the same preflight before writing an original
  gallery file;
- thumbnail generation checks its complete metadata write shape before creating
  the derivative directory;
- each beta/stable/reinstall/restore/rollback update job calls
  `application_update_assert_activation_schema_known()` before the `ready`/activation boundary;
- updater source validation requires `app/services/schema_inspection.php`,
  `app/services/mutation_schema_policy.php`, `app/services/updates_jobs.php`, and
  `app/core-manifest.json`;
- all normal preparation stages checkpoint before active files change, while
  `activate` contains only prepared local replacements and is retry-safe rather
  than pretending to be interruptible;
- migration continuation uses `run_migrations_bounded(1)` and the
  `schema_migrations` row as its durable file-level checkpoint.

`tests/updater_safety_model_test.php` now builds a Phase 10-capable incomplete
snapshot fixture. The fixture contains the two schema-policy services so its
historical assertion still proves that a missing core runtime file such as
`app/views.php` prevents update activation.

Run the Phase 10 focused regression set after any deletion, ingestion, token,
migration, thumbnail, database-maintenance, updater, or mutation-health change:

```text
php tests/mutation_schema_policy_test.php
php tests/schema_inspection_model_test.php
php tests/migration_schema_cache_reset_test.php
php tests/duplicate_photo_ledger_test.php
php tests/browser_upload_settings_test.php
php tests/gallery_migration_model_test.php
php tests/thumbnail_compatibility_model_test.php
php tests/thumbnail_warmup_model_test.php
php tests/database_maintenance_schema_repair_test.php
php tests/updater_safety_model_test.php
php tests/security_schema_system_health_test.php
php tests/translation_catalog_consistency_test.php
php scripts/audit.php --profile=full
```

Manual Phase 10 outage verification must use a disposable installation with a
coordinated filesystem/database backup. Temporarily deny metadata inspection or
select an unavailable database, then verify these workflows are refused **before**
their target mutation:

1. Delete a gallery/image and confirm the source file, gallery folder, and rows are
   unchanged.
2. Move/copy an image or gallery and confirm both old path/ownership and target
   location are unchanged.
3. Add/clear a Duplicate Photo Detector ledger item and confirm the existing ledger
   remains unchanged while System Health shows the ledger capability as unknown.
4. Submit a classic upload and a prepared browser ZIP. Confirm no source image is
   moved/written into the gallery. The PHP temporary upload or prepared package
   should remain the recoverable source for the failed request where the hosting
   runtime permits it.
5. Attempt upload-automation and mobile-WebDAV credential creation/use. Confirm no
   credential is created/trusted. Separately verify revocation still works when the
   full schema is intentionally incomplete but the narrow revocation columns are
   present and inspectable.
6. Start/resume a gallery migration and confirm the job remains resumable with no
   new target original/thumbnail when the relevant preflight is unknown.
7. Generate/repair/delete thumbnails and confirm derivative files are untouched on
   unknown metadata schema. Repeat with a **confirmed absent** metadata table on an
   old-schema fixture to verify the documented file-only compatibility path.
8. Start database cleanup/schema repair and confirm no cleanup batch or repair DDL
   executes while metadata inspection is unknown.
9. Stage an application update and confirm download/extraction may complete, but
   active files are not replaced when activation schema readiness is unknown.
10. Open Admin System Health and Runtime Diagnostics. Confirm all ten mutation
    capability cards use the same state, missing/unknown produces an Action signal,
    and visible/copied diagnostics contain no SQL, raw exception text, DSN,
    password, API/WebDAV token, upload path, migration source path, or staging path.

Restore metadata access and rerun the operations. Also test confirmed-missing states
separately from unknown states: pending migrations should be reported as migration
requirements, while explicitly audited compatibility/bootstrap paths continue only
where documented. This distinction is the core Phase 10 acceptance criterion.

### Phase 11 optional presentation and reporting schema policy tests

Phase 11 adds `tests/presentation_schema_policy_test.php` and extends existing
lightbox, translation, upload-automation, telemetry, dashboard and report source
contracts. The focused policy test is intentionally database-free and uses the
schema-inspection executor seam so `available`, confirmed `missing`, and `unknown`
can be reproduced deterministically.

`tests/presentation_schema_policy_test.php` verifies:

- complete voting storage resolves to `available` and requires exactly **ten**
  first-use metadata probes; checking the same voting capability again in the same
  request performs zero additional probes because object results are cached;
- a confirmed absent voting column produces `missing`, safe optional rendering is
  omitted, `presentation_schema_assert_known()` allows only the audited
  compatibility path, and `presentation_schema_assert_write_available()` blocks a
  write that requires the feature;
- an injected metadata exception produces `unknown`, safe optional rendering is
  omitted, dependent writes throw `PresentationSchemaUnavailableException`, and
  bounded logs contain neither the injected secret marker nor a credential-bearing
  DSN;
- `presentation_schema_health_definitions()` registers exactly fifteen Phase 11
  capabilities and is **lazy**: building the registry performs zero metadata queries.
  Resolving only the voting health entry performs only the ten voting probes;
- complete Picture Game requirements aggregate correctly after composing the voting
  and game-specific storage requirements;
- converted Phase 11 service files, including gallery sidecar creation/import and
  metadata-organizer capture-date readiness, do not contain legacy `db_table_exists()`,
  `db_column_exists()`, or direct `SHOW COLUMNS` policy probes;
- gallery creation/import verifies voting storage before enabling voting and refuses
  unknown lightbox override persistence instead of silently dropping an explicit or
  inherited override;
- the Complete Admin Gallery Report uses structured named-object checks for known
  dependencies, retains the explicitly justified dynamic
  `information_schema.TABLES` base-table inventory query, and does not export raw
  `$exception->getMessage()` database text;
- the AI worker endpoint preflights the AI metadata capability before queue writes
  and its Phase 11 action handler does not return or log raw service/database
  exception text;
- Picture Game bulk mutation uses the exact Picture Game status instead of the old
  unrelated `admin_feature_schema_ready()` aggregate;
- Admin System Health and Runtime Diagnostics consume the final Phase 11 registry.

`tests/gallery_lightbox_mode_model_test.php` now drives lightbox schema readiness
through the structured schema-inspection executor instead of stubbing the old
boolean database helper. This keeps model coverage representative of production
policy.

Run the Phase 11 focused regression set after changes to maps, voting, Picture Game,
lightbox overrides, OpenAI/AI metadata, SimBrief, navigation data, telemetry, the
Admin report, presentation health, or the schema inspector:

```text
php tests/presentation_schema_policy_test.php
php tests/gallery_lightbox_mode_model_test.php
php tests/openai_text_assist_model_test.php
php tests/simbrief_description_model_test.php
php tests/schema_inspection_model_test.php
php tests/migration_schema_cache_reset_test.php
php tests/security_schema_system_health_test.php
php tests/mutation_schema_policy_test.php
php tests/translation_catalog_consistency_test.php
php scripts/audit.php --profile=full
```

Manual Phase 11 verification should use a disposable database or a database user
whose metadata permissions can be temporarily restricted. Verify both confirmed
missing and unknown states separately:

1. Enable GPS maps, then make one required GPS/EXIF metadata object uninspectable.
   The public gallery must remain usable without the optional map, while System
   Health reports the GPS capability as unknown. Restore access and confirm the map
   returns.
2. Attempt to change the per-gallery GPS override while its nullability/column
   definition is unknown. Confirm the setting is not changed and the Admin notice
   points to System Health rather than claiming a migration is missing.
3. For image voting and Picture Game, confirm read-only UI omission where applicable,
   but vote submission, displayed-pair recording, game votes and Admin bulk game
   toggles refuse unknown schema. A confirmed missing migration should instead show
   migration guidance.
4. Make the lightbox override definition uninspectable. Existing gallery viewing may
   use the safe inherited/default mode, but a submitted per-gallery override must not
   be persisted until inspection succeeds.
5. Make OpenAI settings or AI image-analysis storage uninspectable. OpenAI settings
   saves and AI queue mutations must refuse the write. The companion AI worker must
   receive a bounded operational error with no SQL, DSN, raw PDO message, token, or
   private path.
6. Test SimBrief with route-map storage confirmed missing and then unknown. Draft/OFP
   generation may continue, but no route-map database write may be claimed. Unknown
   must appear in diagnostics.
7. Test navigation account persistence with a confirmed pre-account schema and with
   an inspection outage. Session-only compatibility is allowed only for confirmed
   absence. Unknown storage must refuse persistence. Separately verify the narrow
   verified disconnect/delete capability can still remove stored credentials.
8. Test telemetry dashboard/export/settings/maintenance. Confirm a missing schema is
   presented as migration-required, while unknown is presented as database status
   unavailable. Setting changes, rollup, and purge must not silently succeed on
   unknown schema.
9. Export the Complete Admin Gallery Report with one optional section absent and with
   a simulated inventory/read failure. Confirm absent sections degrade safely and
   report output contains generic unavailable text, not raw database exception text.
   Confirm Picture Game statistics show completed selections versus
   displayed-without-selection rows.
10. Disable each feature-flagged Phase 11 capability and load System Health. Confirm
    it reports `disabled` without inspecting that feature's schema. Re-enable the
    feature and confirm the resolver runs and shows available/missing/unknown.
11. Copy Runtime Diagnostics and confirm the same fifteen capability states are
    represented with only validated object identities, safe suggested checks, and a
    request reference for unknown state.

After restoring metadata access, rerun the complete suite. Release acceptance requires
every currently registered PHP regression test to pass, translation catalogs to remain
aligned across English/Czech/German/Swedish, all changed PHP files to pass `php -l`,
all changed JavaScript modules and Node fixtures to parse and pass, the integrity
manifest to be current, the administrator manual to be rebuilt and visually verified,
and no temporary implementation roadmap to remain in the repository or release package.

### Gallery audit remediation regression ownership

The audit-remediation program is protected by focused tests registered in the normal
PHP/Node regression suites. Automated agents should use `scripts/audit.php` according
to `AGENTS.md`; the list below documents ownership and is not an instruction to replay
the tests manually after a successful central audit.

- authorization/media ownership: `public_media_authorization_contract_test.php`;
- corrected session/page-view semantics: `telemetry_semantics_contract_test.php` and
  `telemetry_semantics_fixture_test.php`;
- photo activation lifecycle/privacy: `telemetry_photo_lifecycle_test.mjs` and
  `telemetry_photo_privacy_contract_test.php`;
- traffic segmentation: `telemetry_traffic_segment_contract_test.php`;
- page/image/media/cache/database observability: the `telemetry_*observability*`,
  `telemetry_page_load_metric_contract_test.php`, and
  `telemetry_database_observer_contract_test.php` fixtures;
- cardinality/storage evidence: `telemetry_dimension_normalization_contract_test.php`,
  `telemetry_dimension_normalization_workload_test.php`,
  `telemetry_storage_diagnostics_contract_test.php`,
  `telemetry_daily_rollup_consistency_test.php`,
  `telemetry_report_query_profile_contract_test.php`, and
  `telemetry_report_query_plan_contract_test.php`;
- legacy server-ZIP cache health: `legacy_download_cache_health_test.php` plus the
  download controller/service tests;
- legacy JPEG inventory and explicit cleanup: `thumbnail_compatibility_model_test.php`;
- complete-report semantics: `admin_gallery_report_semantics_contract_test.php`;
- maintenance failure isolation/redaction: `site_maintenance_diagnostics_contract_test.php`;
- final cross-stage integration: `gallery_audit_remediation_stage11_contract_test.php`.

Stage 6 has an intentional production-evidence boundary. The code can reduce new
hourly aggregate dimensionality and expose exact operator diagnostics, but index or
retention changes require representative hosting measurements first. When reviewing
those measurements, compare exact table rows, data/index bytes, approximate recent
rows/day, oldest/newest rows, retention horizons, per-metric dimension cardinality,
request-local report-query timings, sanitized EXPLAIN plans, and completed-day
hourly-vs-daily rollup consistency. The representative normalization workload also
proves that irrelevant page-view dimensions collapse materially while image-level
photo metrics keep their required cardinality. Do not infer an index change solely
from table size, and do not move long-window reports to daily storage while rollup
consistency reports a mismatch or missing daily data.

### 2.1 Release preparation and handoff

`RELEASE.md` is authoritative for release preparation, consistency, packaging, and post-publication qualification. The release-specific agent rule is deliberately stricter than older focused-test lists in this guide:

1. Compare the release worktree with the exact previous stable tag and review migrations, browser/cache-busting changes, translations, packaging policy, generated artifacts, and documentation.
2. Run `php scripts/prepare_release.php <version>` once to update only registered mechanical markers and create a patch-note work item if required. Complete the generated release notes according to `PATCH_NOTES_TEMPLATE.md` and update all behavior-sensitive documentation.
3. Rebuild and visually inspect `docs/PHP_Gallery_Manual.pdf` after the final LaTeX edit.
4. When useful during preparation, run read-only `php scripts/check_release.php`; do not run it redundantly immediately before the final release audit because release consistency is a registered release suite.
5. After the final source/documentation edit, run `php scripts/generate_manifest.php`.
6. Run **only** `php scripts/audit.php --profile=release`. Do not run `quick` or `full` first. Audit profiles are alternatives, not a staircase. The release profile already executes deterministic PHP/Node/WinApp coverage, syntax, contracts, Chromium integration when available, release consistency, manifest freshness, and Git whitespace validation.
7. Inspect `cache/test-audit/latest.md` only when the compact summary requires more detail. Report material `SKIP`/`BLOCKED` coverage explicitly. A PASS with skips is not accurately described as "all tests passed".
8. If any manifest-covered file changes after the release audit, regenerate the manifest and rerun only the release profile.
9. Build and inspect the deployment package. Confirm runtime version, patch-note heading, release metadata/tag, manual version, manifest version, archive name, and intended Git tag agree.
10. Do not create a release commit, tag, push, or publication unless explicitly requested. After publication, smoke-test updater upgrade, migrations, Admin login, public rendering, integrity status, and release-specific critical behavior when practical.

If a local dependency such as PHP, Node, Python, Chromium, Git metadata, database connectivity, or TeX tooling is unavailable, record the exact coverage gap. Do not replace unavailable central coverage with a token-heavy manual replay of the regression tree.

### Version 0.94.2 thumbnail-policy regression

Run `php tests/thumbnail_format_metadata_consistency_test.php`, `php tests/thumbnail_compatibility_model_test.php`, `php tests/public_thumbnail_markup_test.php`, `php tests/public_thumbnail_rendering_model_test.php`, `php tests/thumbnail_warmup_model_test.php`, and `php scripts/audit.php --profile=full` after changing thumbnail compatibility, metadata, manifest, bundle, generation, maintenance, or public rendering code. Verify that the default and explicit `modern` mode advertise only WebP derivatives, including progressive and responsive candidates, even with historical valid JPEG metadata or files present. Verify that explicit `legacy` mode continues to advertise valid JPEG and WebP derivatives, and that cleanup removes stale JPEG metadata whether or not the generated file exists. Confirm old `.jpg` requests in modern mode do not generate files or mutate settings.

### Version 0.94.1 runtime-hardening regression

Run `php tests/version_094_audit_hardening.php` and `php tests/public_media_version_routing_test.php` after changing the root/public rewrite rules, `app/early_runtime.php`, either front controller, updater activation publication, or public media URL generation. With real Apache, verify protected top-level internal trees under both a subdirectory installation and document-root installation return `403` or `404`, while later public slug components with the same words still route normally. Verify uncaught, PDO-style, missing-require, fatal-shutdown, JSON, and already-streaming fixtures with `display_errors=0`; no emergency response may expose a path, trace, SQL, or credential. Simulated activation must return private/no-store `503`, completed state must recover, corrupt state must fail closed, and all temporary marker state must be removed. Public originals must have stable version identities, unchanged payloads, and conditional `ETag`/`304` behavior. Production acceptance also requires `display_errors=0` at the hosting layer.

When checking Admin dashboard performance, verify that opening `?page=admin` does not request `admin_dashboard_maintenance` until `#admin-tab-maintenance` is selected. Then verify that the authenticated JSON response replaces the placeholder, nested maintenance tabs initialize, and direct or no-JavaScript fallback links remain usable.

### Public Lightbox Zoom Verification

Run all seven PHP zoom contracts and the Node model test listed above after changing lightbox markup, browser events,
fullscreen/mobile CSS, quality candidates, cache-busting, translations, maps, voting, or lazy metadata. The model test
covers scale bounds, reset, two-axis pan clamping, centered and fractional anchors, repeated off-center zoom through 400%,
required source pixels, density caps, malformed candidates, and no-downgrade selection. PHP contracts cover semantic
controls, reset ordering, server/lazy candidate rendering, immediate deliberate-zoom source promotion, passive 100%
quality evaluation, accessible loading feedback, stale-request cancellation, failure fallback, fullscreen/map
remeasurement, event scope, browser-modifier preservation, catalog coverage, and the existing gallery/NSFW access
boundary. Also run `node --check` on every changed JavaScript module and `php -l` on every changed PHP file.

Manual browser coverage remains required because the repository has no production browser-automation dependency:

1. In current Chromium/Edge, Firefox, and Safari/WebKit where available, open a normal gallery photo and use `+`, `−`,
   percentage reset, `+`/`=`, `-`/`_`, `0`, wheel/trackpad, drag, and pinch. Verify the limits at exactly 100% and 400%.
2. Verify the 100% photograph is centered and the photograph frame itself grows when zooming. In normal lightbox the
   enlarged frame may extend beyond the original stage instead of behaving like a fixed crop window. In fullscreen the
   browser viewport remains the clip boundary.
3. Put the pointer on a recognizable off-center detail and zoom repeatedly from 100% toward 400%. The same photograph
   point must remain under the cursor after every step, including rapid wheel input while the 120 ms transition is still
   animating. Repeat with touch pinch around an off-center midpoint. Confirm there is no cumulative top-left or
   bottom-right drift.
4. While zoomed, drag to all photograph edges. In fullscreen verify both horizontal and vertical pan are available for a
   wide image once zoom creates overflow. Confirm the close button and other fullscreen HUD controls remain clickable at
   125%, 200%, and 400%, and that photo pan does not steal their pointer events.
5. In desktop browsers, verify unmodified mouse-wheel and two-finger trackpad scrolling **do not zoom** an image.
   They pan an enlarged image horizontally and vertically and have no scale effect at 100%. Ctrl+wheel and a physical
   trackpad pinch zoom only the photograph at the pointer, even in fullscreen; Command+wheel remains browser-owned.
   Neither gesture changes the HUD's size or navigates photographs. Check zoom limits at 100%/400%, including small
   deltas, large mouse-wheel steps, Safari GestureEvents and scrolling entirely outside the photo stage.
   On iPhone Safari/Chrome and Android Chrome, at 100% a one-finger horizontal swipe must navigate; at larger scales one
   finger pans and two fingers pinch. Begin a swipe, add a second finger, pinch, then lift one finger and continue panning:
   no accidental next/previous or synthetic stage-click/fullscreen toggle is allowed. Cancel a gesture mid-motion.
6. With Network and Elements tools open, use an image substantially wider than the generated preview. A sufficiently
   small 100% stage may remain on the preview, while a large/high-DPI stage may be promoted passively. Perform one
   deliberate zoom-in action and confirm a high-priority request for the protected `data-full-src` begins in that same
   input task. The request must not wait for resize, fullscreen, an animation frame, or a mode toggle. While it transfers,
   the existing preview remains usable and the translated loading pill/ring plus `aria-busy` are present. When the live
   image finishes loading the original, sharpness must improve in the current mode without changing scale, pan, or
   fullscreen state.
7. Navigate previous/next while an original request is pending, select picture-strip and 3D-carousel neighbors, and open
   a lazy non-visible item. A late result must never mutate the new photograph. Each new image starts centered at 100%,
   and zooming one photograph must not prefetch adjacent originals. Simulate a failed full-media request and confirm the
   protected preview is restored without repeated retries.
8. Toggle browser/CSS mobile fullscreen while enlarged and confirm scale is preserved, the fitted frame is recentered for
   the new viewport, and translation is safely reclamped. Start slideshow and confirm zoom resets before automatic
   navigation. Open/close fullscreen map split and confirm Leaflet, votes, help, metadata, strip/carousel items, navigation,
   and the close button remain independent controls.
9. In a physical gallery with enough GPS-tagged photographs to span multiple HTML pages, open the gallery map and choose
   **Open photo** on a marker from the current page, then on a marker beyond the current page. Both actions must open the
   correct photograph in the existing lightbox without exposing a raw-media URL. Repeat in desktop fullscreen split-map
   mode and confirm the map stays mounted while only the photograph pane changes; click two markers rapidly and confirm
   the second click does not toggle fullscreen on the underlying stage. From the ordinary map overlay, confirm the overlay
   closes before the selected photograph appears. Re-select a previously loaded off-page marker and confirm no new target
   request is needed. Delay metadata responses, select a second marker, then close the map or viewer: cancelled/older work
   must neither change the photo nor navigate away, including after reopen. Repeat after exiting fullscreen or changing
   photos with the keyboard. Fail a current lookup deliberately and confirm its canonical photo-page fallback still works.
   Copy a popup's canonical link, disable JavaScript, and open that URL directly to verify the authorized page fallback.
   Repeat as a public visitor and Admin, with password/share access where configured, and confirm no access rule is weakened.
10. After changing `lightbox.js`, reload page source and confirm `data-gallery-asset-revision` changes even if another
   dependency has a later filesystem modification time. Confirm the deferred `lightbox.js?v=...` request uses the new
   revision. Disable JavaScript and confirm the ordinary server-rendered photo/navigation fallback remains usable. Watch
   the console and memory while repeatedly opening, zooming, promoting, navigating, toggling fullscreen, and closing;
   there must be no stuck pointer capture, stale pan state, uncancelled listener, unbounded decoded-source cache, or
   zoom-caused URL/history change.
11. On a parent gallery view with no lightbox-capable photo cards, create a subgallery through the Admin side panel, then
   delete a visible subgallery card and repeat create/delete once more without reloading the browser. Each mutation must
   refresh the parent fragment in place, and the console must remain free of temporal-dead-zone errors such as
   `Cannot access 'lightboxHiddenCleanupTimer' before initialization`. This specifically exercises teardown of an earlier
   `setupGalleryLightbox()` instance that returned before mounting a viewer.
12. Enter fullscreen or mobile fullscreen and trigger a full-quality load (passive 100% promotion or deliberate zoom) on a
   photograph large enough to transfer for a visible duration, throttling the network if needed. Confirm the shared
   progress bar with a percentage and a dynamically unit-scaled byte readout (for example `8.4 MB / 22.7 MB`) appears at
   the bottom of the stage in normal, fullscreen, and mobile-fullscreen modes, fills smoothly toward 100%, and disappears
   once the sharper image is installed. Confirm the obsolete compact loading pill/ring is absent. With Network tools
   open, start a full-quality transfer, then immediately navigate to another photograph, close the lightbox, or trigger a
   second deliberate zoom before the first finishes: the in-flight request must show as cancelled, the aborted transfer must
   not be recorded as a failed source, and the progress bar must not resurrect on the wrong photograph. Repeat with a
   simulated failed/aborted response and confirm the existing preview-recovery behavior from step 7 is unaffected.

### 3. Manual Functional Smoke Tests

Map-photo navigation regression commands:

```text
php tests/seo_lightbox_target_guard_test.php
node tests/lightbox_map_navigation_test.mjs
node tests/lightbox_map_browser_test.mjs "C:\Program Files\Google\Chrome\Application\chrome.exe"
```

The PHP test executes the real request guard in isolated public/Admin requests, including rejection of unknown parameters
and rejection of `target_image_id` on an endpoint that does not support it. The Node test executes production closure
functions with controlled fetch/DOM/timer boundaries; it covers detached-cache reuse, cancellation, supersession, close/reopen,
and genuine-failure fallback. The browser runner requires an installed Chromium executable and uses a fresh profile under
`cache/` plus a loopback-only fixture server. It loads the complete production lightbox module with synthetic photographs
and controlled metadata responses. Its popup-shaped links exercise real DOM event routing and CSS fullscreen split state;
it does not validate Leaflet rendering, native fullscreen, touch gestures, or live database/media authorization. Run the
manual matrix above for those integration checks. `--baseline` on either Node runner loads the committed `HEAD` lightbox
source to reproduce a regression before committing a fix.

For feature work, use the same end-to-end scenario every time. Keep one dedicated test installation or local database so you can create and remove test content freely.

Recommended flow:

1. Log in as admin.
2. Open the dashboard and confirm it renders without errors.
3. Create a new gallery. Type at least two characters matching an existing sibling title and confirm the newest sibling suggestion appears; accept it once with `Tab`, once with `ArrowRight`, and once by pointer. Change the selected parent and confirm ranking updates. Repeat in a dynamically opened right-side-panel form, then verify ordinary manual entry and the JavaScript-disabled form still submit the normal title field.
4. Edit gallery title, description, manual date range, visibility, tags, and ordering settings.
5. Upload 2 to 3 images.
6. Open the gallery on the public site.
7. Open an image in the lightbox.
8. Reorder images if the change touches ordering.
9. Open an existing gallery that contains photos with EXIF dates, use **Apply to this gallery**, and confirm the From/To fields plus the compact suggestion fragment update without a full page reload when JavaScript is enabled. Repeat from a side-panel editor opened while viewing that gallery, then from its parent/root gallery-card context. In both cases confirm the browser URL remains unchanged, the drawer stays mounted, the server-rendered hero/card date updates through the shared mutation coordinator, and the resulting branch range uses the en dash separator. For the enhanced endpoint, also verify expired Admin authentication returns JSON with `error_code=auth.admin_required` and an invalid CSRF token returns JSON with `error_code=security.invalid_csrf`, rather than a login redirect or plain-text response.
10. Open **Review branch suggestions** for a parent gallery and confirm the table only lists that gallery and its subgalleries.
11. Open Admin **Gallery dates** after scanning images with EXIF dates, then apply one suggestion and confirm the gallery card displays the resulting date range.
12. Rename or move the gallery if the change touches file or path logic. Confirm the public URL uses lowercase ASCII slugs, contains no encoded spaces or diacritics, and still resolves after moving the gallery under another parent.
13. Create a gallery named **Testovací fotky** with a child named **Test nahrání** and confirm the child URL is `/gallery/testovaci-fotky/test-nahrani/`.
14. Delete the test gallery and confirm cleanup succeeds.


### Setup Wizard release acceptance

The central audit owns the PHP `admin_setup_wizard_*` contracts, the registered Node browser-module fixture, reversible URL tests and shared Theme-layout coverage. They cover registry completeness/localization, strict normalization, owner-bound draft revisions, skip and navigation behavior, final approval, conflicts before writes, transaction rollback/URL compensation, telemetry row locks, secret redaction, preview hooks, inclusion controls, subsection switching and summary disclosure. The Node wizard fixture uses a synthetic DOM; it does not replace authenticated browser review.

For the final Version 0.111 browser smoke review, use disposable data and record the browser/device and results in the release qualification record:

1. Start and resume from Settings, visit all eight sections at desktop and narrow mobile widths, and verify the launcher remains visible after Settings navigation/save.
2. Switch in-step subsections, expand Advanced/Expert groups, change values and toggle inclusion off/on. Confirm values survive presentation switches and no setting is persisted before Apply.
3. Check supported appearance/layout/GPS/hero-tag/media preview against Theme; exercise both `progressive` and `responsive` thumbnail choices without weakening access checks.
4. Skip one setting and a whole section, navigate Back/Next, and verify the final review exposes changed values first. Show/hide unchanged settings and confirm unchanged-only groups follow the control.
5. Apply only after explicit approval, reopen Settings and verify persistence. Test stale revision and concurrent setting changes; verify refusal rather than overwrite. Cancel/restart must discard the draft.
6. Check coupled Trash and Scheduled Maintenance preferences, telemetry availability/refusal, and website-address rollback using disposable configuration. Keep specialist operations informational and inspect output for secret leakage.
7. Disable JavaScript and repeat navigation, skip, approval and save. Verify keyboard access, labels, readable validation errors, and subsection/disclosure content.

### Centralized Admin Settings Tests

Run the focused contracts after changing the Settings hub, any registered setting owner, Admin navigation, or shared tab behavior:

```bash
php tests/admin_settings_registry_test.php
php tests/admin_settings_normalization_test.php
php tests/admin_settings_navigation_contract_test.php
php tests/admin_settings_rendering_contract_test.php
```

The registry test checks stable unique IDs/keys, known sections, ownership metadata, secret redaction and specialized routes. The normalization test locks safe thumbnail fallback, browser-upload numeric clamping, central site-name normalization and unknown-write rejection. The navigation test locks the route, Admin menu, specialized backlinks, Gallery tags deep link, stable section IDs and href-history tab mode. The rendering contract checks headings, fieldsets, labels/help wiring, tab ARIA state, hidden inactive panels, error summary and secret redaction.

Manual browser verification:

1. Load every direct section URL from `general` through `advanced` and confirm the section query agrees with the visible heading and does not add a scroll-driving fragment.
2. Change sections with pointer and keyboard. Verify arrow/Home/End tab movement, Browser Back/Forward, and refresh preserve the active section.
3. Disable JavaScript and use the section links. Each link must load the correct server-selected section and all specialized links must remain usable.
4. At mobile width, confirm the top-level tab strip remains a single horizontally scrollable row rather than a multi-line wall.
5. Submit each centrally editable group and verify only that group is posted. Confirm success notice and redirect stay on the same section.
6. Force an invalid language/renderer value with a direct POST test and verify a page-level error summary plus field error. Unrelated fields must retain submitted values, including unchecked checkboxes.
7. Verify Theme, Tags, Upload settings, Telemetry, Account and Dashboard settings still save directly when opened without visiting the central hub.
8. Verify central links to Theme Gallery tags use `appearance_subtab=admin-theme-appearance-subtab-gallery-tags#admin-theme-tab-appearance`.
9. Confirm `password_reset_smtp_password`, site-maintenance tokens, OpenAI keys and upload API keys never appear in central page source, error output or Admin logs.
10. Re-run `hero_tag_theme_model_test.php`, `tag_page_theme_model_test.php`, upload/settings tests, telemetry tests and the complete `php scripts/audit.php --profile=full` suite.

#### Website address configuration

The central audit discovers `tests/site_url_config_test.php` for URL validation, source preservation, and safe filesystem persistence/refusal. It uses disposable configuration files rather than the checkout's `config.php`.

For browser acceptance, use an isolated installation with a writable configuration and directory. Open **Settings > Website address**, save a loopback HTTP/HTTPS URL with a real installation path where applicable, and confirm the redirect uses the new address and returns to the `site` section. Compare configuration bytes and database settings before and after: only the top-level `base_url` literal should change, with no database setting update. Repeat without JavaScript. Submit an empty address, credentials, query, fragment, and malformed URL; verify field errors and unchanged configuration. Repeat with an unwritable file/directory and a computed `base_url` expression; verify refusal preserves the original file.

### Public Thumbnail Rendering Smoke Test

Use a gallery with enough photos to create several viewport lengths. Test with browser DevTools, an empty cache, and a simulated slow connection. Perform the checks both anonymously and while logged in.

1. Leave Admin > Theme > Layout > Public thumbnail rendering on **Progressive thumbnail sharpening - Default**. Confirm a missing/fresh setting also selects this mode. On an upgraded installation, confirm the renderer migration changes the previously persisted responsive default to progressive and bumps the public content revision.
2. In the Elements panel, confirm progressive photo cards contain server-rendered `<picture>/<img>` markup, expose only the small candidate in the active `srcset`, and keep larger bounded candidates inert in `data-progressive-srcset` before browser activation. The `src` should prefer the 300 px derivative when available.
3. In Network, reload with an empty cache. Confirm the small progressive request begins first and larger requests appear only after renderer activation for visible or near-visible cards.
4. Switch to **Responsive browser selection - Legacy**. Confirm responsive photo cards expose their complete available bounded WebP/JPEG `srcset` immediately and the browser directly requests the candidate it selects without requiring JavaScript. Then switch back to the progressive default for the remaining checks.
5. Reload with an empty cache and slow throttling. Confirm the small request begins first. Larger requests must begin only after the small image is loaded and only for visible or approximately 720 px near-visible cards. Scroll slowly and verify far-offscreen cards remain unupgraded.
6. In Network, verify no more than 2 progressive larger preload/decode jobs are active at once. Visible cards should overtake merely near-visible queued cards when both are waiting.
7. Watch one sharpening card under heavy throttling. The small image must remain visible until the replacement loads/decodes. Force a larger request to fail and confirm the small image remains functional. No fake percentage indicator should appear.
8. Resize the window and change device emulation DPR. Relevant cards may upgrade further when a larger candidate is required, but they must not downgrade, loop indefinitely, or repeatedly download an already adequate candidate.
9. Check for accidental double downloads by filtering Network to one photo basename. In progressive mode, one small transfer plus at most the needed larger replacement is expected. Repeated requests for the same larger URL after resize/reinitialization indicate a regression. Responsive mode should not perform the progressive small-then-large sequence intentionally.
10. Disable JavaScript and reload progressive mode. Confirm small thumbnails, direct photo/gallery links, alt text, layout, password/access behavior, and navigation still work. Responsive mode must remain fully functional too.
11. Confirm stable card dimensions before decode. Stored intrinsic width/height should be present when known, and the existing thumbnail background should paint the slot without shifting surrounding cards.
12. Open the lightbox, vote on an image, use photo maps where available, search/navigate normally, and exercise thumbnail warm-up fallback. These features must behave identically in both modes.
13. Verify restricted NSFW cards and inaccessible/password-protected galleries do not expose protected thumbnail/media URLs through progressive data attributes.
14. Enable `prefers-reduced-motion: reduce`. The progressive renderer introduces no required pulse/shimmer animation; the card remains static while sharpening occurs.
15. Compare perceived readiness rather than claiming total bytes are lower. Progressive mode can transfer both the small image and a larger replacement, so record transfer totals separately from first useful paint/interaction observations.

The browser/network observations above are manual verification only. The standalone PHP and Node tests do not claim coverage of real browser request scheduling or visual decode behavior.

### Gallery Hero Tag Theme Smoke Test

Also verify the adjacent public tag-page settings: use Configure tag display from Edit tags and confirm it opens Theme > Appearance > Gallery tags; set columns, rows, and card design; save; and verify the dedicated grid, pagination capacity, and card layout on a public tag page without changing ordinary gallery pages. Use Manage tag metadata to verify the reverse link. Run php tests/tag_page_theme_model_test.php.

Use a gallery with more than 20 direct and/or contained tags, including several tags with deliberately different assignment frequencies. Perform the public checks both anonymously and while logged in, because the two render pipelines use different browser entrypoints.

1. Open **Admin > Theme > Appearance > Gallery tags**. With a fresh installation or missing settings, confirm **Most used first** is selected, **Display every tag immediately** is off, the initial tag limit is 20, scrollbar support is enabled, and its row threshold is 5.
2. Move the visible-tag slider and confirm the exact number field follows it. Edit the number field and confirm the slider follows it. Repeat for the scrollbar-row slider and number field. Values must remain within 1 to 200 tags and 1 to 12 rows.
3. Enable **Display every tag immediately** and confirm the initial-limit controls are hidden because they no longer affect public disclosure. Disable it and confirm the controls return with the previous value. Disable the scrollbar and confirm its row controls are hidden; re-enable it and confirm the saved row value remains available.
4. Save the Theme page, reload it, and confirm all five values persist. No database migration should be required because the settings use `app_settings`.
5. Open the tagged public gallery at desktop width. Confirm the hero tag panel itself and each tag list use the full available hero width. Tags must continue wrapping toward the right edge rather than stopping at the normal readable paragraph width.
6. With the default 20-tag limit and more available tags, verify the first response already limits the preview, including before module startup. Open the ellipsis control and confirm the shared animated panel shows every direct and contained tag in server order without a request, reload or change to header dimensions. Close with the summary, outside click and Escape; verify Escape restores focus. Check Theme animation duration, zero duration and reduced motion.
7. Put the preview limit exactly at a group boundary and confirm a containing-group label is absent when none of its tags are previewed; the panel must retain the heading and every group. Test an exact-limit gallery and display-all mode: neither should render an unnecessary disclosure control.
8. Switch to **Alphabetical**, save, and verify each semantic group is alphabetically ordered. Switch back to **Most used first** and verify tags with more direct gallery plus photo assignments appear first within their own group; equal counts should be alphabetical. Direct gallery and contained groups must not be merged together.
9. Resize through desktop and narrow widths with long tag names and classic scrollbars. The server-rendered row cap must remain stable at module startup. Verify wrapping preview pills and internal scrolling only when content exceeds the cap; disabling the scrollbar permits natural growth. Open the panel and verify its bounds remain inside the viewport, its tags wrap and neither the header nor the preview clips it. Confirm no horizontal page overflow.
10. Disable JavaScript and reload. Confirm the configured preview and native keyboard disclosure remain available from the initial HTML; opening places the complete grouped tag panel below the header and keeps long lists reachable by scrolling without changing header dimensions. Re-enable JavaScript and repeat anonymously and in the authenticated public view.
11. Replace the header fragment dynamically and repeat opening/closing without rebinding. Open a card panel while the header panel is open and confirm shared dismissal. The registered `tests/fixtures/gallery_tags.html` uses production markup and checks group-boundary, exact-limit and display-all variants, first-paint and open/close geometry, native Enter, Escape/focus, narrow viewport bounds, clipping, long names and delegated replacement. `tests/hero_tag_theme_model_test.php` retains the Theme/rendering contracts. Run the central audit profile appropriate to the task; release preparation uses only `php scripts/audit.php --profile=release`.


### Duplicate Photo Detector Smoke Test

1. Apply pending migrations, including `202608080001_duplicate_photo_ledger.php`, then log in as an administrator and open a gallery containing prepared duplicate photos across the selected gallery and one or more nested subgalleries.
2. Open **Find duplicate photos** from the gallery Images section and confirm it uses the existing right-side Admin panel rather than a second modal or standalone route.
3. Confirm **Search all galleries** is unchecked on a fresh detector view. Run local and explicit global scans and verify the scope labels and bounded AJAX progress while the panel remains open.
4. Verify exact SHA-256 and normalized-EXIF possible matches still behave as specified, including different file sizes for valid EXIF candidates and rejection of size-only matches.
5. Confirm completed findings are rendered as deterministic left/right pairs. Verify each side shows image id, filename, file size, dimensions/MIME where stored, EXIF/camera/lens context, and matching signals.
6. Click each gallery title/path and verify it opens the correct public gallery in a new tab. Click each preview, filename, and gallery-relative path and verify it opens the correct public photo context in a new tab. The Admin page and detector panel must remain unchanged.
7. Click **Ignore this pair from now on** on one finding. Verify the action completes through AJAX with no reload/navigation, the right-side panel remains open, the pair disappears immediately, and the ledger count increases. In the JSON response verify `mutation.type=duplicate_photo_ledger.ignore_pair`, `panel.workflow=duplicate-photo-detector`, and an empty `contexts` array because ledger state does not alter the public gallery render.
8. Start a new duplicate search with the same administrator and verify the ignored pair is not shown again while other relationships from the same source group remain eligible.
9. On a left/right pair from different galleries, click **Ignore all from this gallery** on only one side. Verify all currently displayed/future pairs involving that exact gallery are suppressed. Verify a parent or child gallery with a different gallery id is not suppressed automatically.
10. Repeat the exact-gallery action from the opposite side of another pair to confirm left/right controls are independent and the server derives the stored gallery from the submitted result image rather than a browser-provided gallery id.
11. Use **Clear ledger**. Verify it runs through AJAX, the panel stays open, counts return to zero, and a new search can show previously ignored pair/gallery findings again. Verify the successful response uses the canonical `duplicate_photo_ledger.clear` mutation envelope.
12. Confirm ledger decisions are per administrator by testing with a second administrator account when available. One account's ignored pairs/galleries must not suppress another account's results.
13. On a disposable duplicate, press **Delete this** once. Confirm there is no confirmation dialog, no reload/navigation, the browser URL stays unchanged, the panel remains open, and refreshed pair counts/results reflect the deletion.
14. Confirm deletion reuses the existing gallery image mutation semantics for original files, image rows, derivatives, cover references, path safety, and Admin logging. Repeat for a nested subgallery and global-search result.
15. Confirm forged/stale pair IDs, image IDs, moved images outside an immutable local scope, and missing/expired detector jobs are rejected server-side. For AJAX requests, also test an invalid CSRF token and an expired/non-admin session: both must return JSON error envelopes rather than plain text or a login-page redirect.
16. Disable JavaScript or use the detector route directly and verify normal POST/redirect forms still work as fallback for scan continuation, ledger actions, and explicit deletion. This fallback is not the expected JavaScript interaction path.
17. Run `php tests/duplicate_photo_detector_test.php`, `php tests/duplicate_photo_ledger_test.php`, `php tests/migration_consistency_test.php`, and the full `php scripts/audit.php --profile=full` suite.


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
- If an action starts in the Admin right-side panel, does the JavaScript path keep the panel open and avoid page navigation/reload?

If the answer is yes to any of those, run the manual smoke test in addition to syntax checks.


### Admin side-panel mutation pipeline

For any change to a persistent Admin side-panel mutation, its server response, batching layer, or dynamic form wiring, run the repository contract check first:

```bash
php scripts/check_admin_mutation_contracts.php
```

In a source checkout that contains the tracked regression tree, run `php scripts/audit.php --profile=full`. Invoke focused mutation-related PHP/Node tests separately only when diagnosing an audit failure or reproducing a specific mutation regression. Deployment ZIPs intentionally exclude `tests/`, so absence of that directory in a deployment artifact is not a passing test result and must be reported as an unavailable source-tree validation step. Run `php -l` on every changed PHP file and `node --check` on every changed JavaScript file.

The mutation contract check protects strict canonical-envelope consumption, stable ID survival through classic upload aggregation, Metadata Organizer envelope preservation, dynamic form interception, centralized retry ownership, the known bulk visibility/NSFW JSON return boundary, exact effective-visibility postconditions for gallery Published/Unpublished transitions, direct-card-first gallery membership verification, current-origin posting of server-rendered side-panel mutation forms, clean JSON authentication/CSRF/delete response boundaries, and the absence of hard reload/history rewriting in enhanced side-panel completion. It intentionally permits direct-page navigation fallbacks outside the mounted-panel success path.

For manual browser qualification, exercise the highest-risk applicable operations: child gallery create; title edit; gallery Published -> Unpublished -> Published transitions; slug/folder rename; reparent; delete; upload to existing gallery; create-with-upload; image delete/move/visibility/NSFW/cover; scan/import; Media Renamer; Metadata Organizer; Duplicate Detector delete; image reorder/edit; tag slug edit; and Smart Gallery placement. For every applicable case verify the persisted server state, panel remains mounted/open, browser URL stays byte-for-byte unchanged, no full-page navigation/reload occurs, the current public context reaches the declared postcondition, replacement controls work on the next mutation, back/forward remains sane after closing the panel, and the direct-page/non-JavaScript fallback still redirects correctly. For gallery visibility transitions specifically, the declared postcondition must be `gallery_visibility` with the newly persisted effective visibility rather than a timestamp-only proxy.

For the parent-gallery card workflow, explicitly test creating a child while viewing its parent and confirm the new server-rendered card appears without reload. Repeat on an unpaginated parent and on a paginated parent where the new child may sort onto another page; direct card presence must win over an auxiliary count mismatch, while off-page completion must still require the full context count to converge. Then delete a visible child through its card trash button and confirm the request returns `application/json`, the card disappears without reload, and the browser URL is unchanged. Repeat with an expired Admin session and an invalid CSRF token: both must return JSON with `error_code=auth.admin_required` or `error_code=security.invalid_csrf`, never an HTML login page or plain text. On a disposable installation with `display_errors=1`, inject a temporary warning inside the gallery-delete mutation path and confirm the browser still receives valid JSON while `gallery.public_json_output_discarded` or the PHP error log records the discarded diagnostic. Also test the same installation through any supported host alias/scheme used in development or hosting and confirm server-rendered form fetches retain the active session by posting to the current origin.

### Resumable application-update verification

Do not run destructive update tests against a real installation. Automated updater tests must use temporary directories, fake job state, corrupt synthetic archives, or static wiring assertions unless a disposable filesystem and database are explicitly configured.

For a disposable shared-hosting test instance, verify these interruption points separately:

Verify the compact release summary at desktop and narrow widths. Complete an update and confirm the installed version/channel/status refresh in place, obsolete stable-update controls disappear, the URL stays unchanged and the panel remains open. Induce a passive summary failure, retry from the dynamically rendered control, and confirm installation is not repeated and no GitHub discovery request is issued.

1. Start a stable update and close the browser during download, extraction, manifest validation, file staging, and backup. Reopen **Updates** and confirm the same job id resumes from its persisted cursor.
2. Kill a worker after a bounded request returns and confirm completed stages do not repeat. Create a synthetic archive with more than 500 entries and confirm `archive_validate_index` advances in multiple requests. For a package-stage failure, confirm Retry discards untrusted archive/extract artifacts, Range validators, and source URL state before restarting download.
3. Open the update page in two browser sessions and confirm only one worker advances the job. Address an older failed/running job id directly while another job owns `active-job.json` and confirm the old job cannot execute, retry, cancel, or clear the active owner. Also leave stale text in `worker.lock` without holding the OS lock and confirm the next worker proceeds.
4. Corrupt or truncate a ZIP, add a traversal entry, add a symbolic link, add a file above 32 MiB, exceed the expanded-size cap, remove a required runtime file, modify a manifest-covered file without updating its hash, and add an installable managed file without adding it to the manifest. Each case must fail before `activate`. Confirm deterministic manifest/hash/version mismatches hide retry, retain cancel, and explain that a newer build is required. For a resumed stable download, change the branch snapshot between slices and confirm `If-Range` causes a clean restart rather than an append across two snapshots.
5. Confirm byte-identical release files are excluded from `activation_files`, then confirm `ready/` and `rollback/original/` are complete before the first active-file replacement. Also confirm a managed symbolic-link destination and an oversized (>128 MiB) active rollback file fail before activation. A pre-activation failure must leave the active tree byte-for-byte unchanged.
6. Interrupt activation only on a disposable installation. The next worker must recognize already matching prepared hashes and finish the remaining files. This test documents the unavoidable mixed-tree window on hosts without atomic release-directory switching.
7. Add a disposable migration whose callback is intentionally interrupted once, then rerun it. Verify the migration definition is safe to replay and that a recorded `schema_migrations` version never runs again.
8. Before activation, use **Cancel prepared update** and confirm the active job is released and application files remain byte-for-byte unchanged. After a completed or failed post-activation update, run **Rollback application files**. Confirm the pre-update file snapshot is restored through a new resumable job and that database migrations are not reversed. Confirm cancellation is rejected once activation has begun.
9. Initiate the update from the Admin side panel. Progress must refresh in place, dynamic forms must remain intercepted, and no normal success path may call `window.location.reload()` or assign `window.location.href`. Repeat with JavaScript disabled and use the normal Continue/Retry POST fallback.
   Start a beta install from **Advanced tools** and verify the synchronized job card and progress bar remain visible in both **Advanced tools** and **Status** throughout the job.
10. Close the browser with a running Admin job, reopen the update UI, and confirm continuation starts from durable server state. For unattended stable updates on an idle site, run `php scripts/application_update.php --time-budget=8` repeatedly or from cron and confirm it advances only `trigger=background` jobs. When no job exists, confirm one invocation performs bounded metadata discovery and only creates the job; package work starts on the next invocation.
11. Run with low `max_execution_time` where possible. Confirm the reported worker budget stays below the PHP limit reserve and remote metadata discovery stops at its own request budget. Do not treat successful `set_time_limit()` calls as evidence of safety; the updater must remain correct when that function is disabled or ignored by hosting.
12. Inspect Admin JSON/log output from induced transport, ZIP, migration, and filesystem errors. Only generic text plus a short reference fingerprint may be exposed. Paths, URLs containing tokens, raw SQL, credentials, stack traces, and exception messages must not appear.

For side-panel work, full-page POST/redirect behavior is a fallback test, not the expected JavaScript behavior. Test the in-panel path first. A panel action should update its fragment or affected page elements in place. If the feature is specified as one-click, also verify that no unrequested `window.confirm()` or other intermediate prompt was introduced.
For persistent side-panel mutations such as ignore/review ledgers, verify every action through the JavaScript path first: the request must ask for JSON, the browser URL must not change, the panel shell must stay open, only owned fragments/page elements may refresh, and controls rendered by the replacement fragment must still be intercepted by delegated handlers.

## Recommended Habit
Keep a short test note for each significant change:

- what changed
- what you tested
- what you did not test
- any warning signs or follow-up work

That makes regressions easier to track and helps future changes focus on the highest-risk paths first.

### Duplicate Photo Detector side-panel deletion

1. Open a gallery while authenticated as an administrator and launch **Find duplicate photos** from the existing right-side Admin panel.
2. Complete a scan that contains at least one duplicate group.
3. Click **Delete this** once and verify no browser confirmation dialog appears.
4. Verify the delete request starts immediately through AJAX, the browser URL does not navigate to the standalone Admin Duplicate Photo Detector page, no full-page reload occurs, and the right-side panel remains open.
5. Verify only the detector fragment refreshes in place, the deleted photo disappears immediately, and a group with only one surviving member is removed.
6. Repeat from a result belonging to a nested subgallery and, separately, from **Search all galleries** scope.
7. Refresh the underlying gallery afterward and verify the deleted image remains deleted and no unrelated image was removed.
### Smart Galleries

Run the focused Smart Gallery regressions first:

```bash
php tests/smart_gallery_rules_test.php
php tests/smart_gallery_cycle_placement_test.php
php tests/smart_gallery_presentation_test.php
php tests/smart_gallery_public_contract_test.php
```

`smart_gallery_presentation_test.php` exercises Theme/site inheritance, malformed JSON, unknown presentation versions, explicit booleans, invalid grid/renderer/lightbox values, Admin preview precedence, normalized thumbnail ranges, and physical-gallery thumbnail guardrails. `smart_gallery_public_contract_test.php` protects the shared public membership predicate, stable ordering, bounded page/lightbox windows, route policy, side-panel enhancement, preview renderer reuse, download authorization structure, and normal-gallery slideshow default.

Then run `php scripts/audit.php --profile=full`. Manually create a tag-plus-rating collection, preview and publish it, verify multiple pages, then move one image between three and five stars and confirm membership changes immediately. Test nested logic, deleted references, query-string and clean URLs, search conversion, and inaccessible matching images. Logged-out counts, covers, page rows, lazy lightbox metadata, and downloads must exclude private, locked, unpublished, share-only-without-valid-access, and otherwise inaccessible source galleries.

Test all placement modes: unlisted must remain absent from listings; root must participate in homepage pagination; gallery mode must render independently around the selected physical parent's normal content. For one parent attach at least two Smart Galleries above and two below, give them differing and equal order values, and verify the sequence is `top -> normal subgalleries/photos -> bottom` with Smart Gallery ID as the equal-order tie breaker. Attach one Smart Gallery beneath multiple physical galleries, change placement/order in only one parent, remove it from another, and verify every other assignment remains intact. Disabled or private Smart Galleries must remain absent regardless of placement and must not leave an empty attachment panel.

For presentation, first leave **Override Theme defaults** disabled and change the global grid, thumbnail renderer, and lightbox mode. Verify the Smart Gallery follows those changes. Enable its override and test columns, rows, pagination, thumbnail min/max, responsive/progressive rendering, gallery-card layout, metadata, lightbox mode, slideshow, voting, and download. Save, reload, and confirm persistence. Corrupt or remove `presentation_json` in a test database and confirm the page safely inherits defaults. Verify conflicting Smart Gallery thumbnail bounds never bypass stricter physical gallery/image bounds.

For lightbox, use a Smart Gallery whose result set spans at least three HTML pages. Open the last item on page 1 and navigate forward, then open the first item on page 2 and navigate backward. Continue across another boundary. Verify keyboard arrows, swipe, fullscreen, zoom, close/reopen, optional slideshow, and the existing stale-request behavior. In Network tools, each `smart_gallery_lightbox_data` request must be bounded to at most 80 metadata rows and opening the page must not download all originals or all result metadata.

For cycle safety, create a Smart Gallery whose positive gallery rule selects physical Gallery A and attempt to attach it to A; the server must reject the relationship. Repeat with a multi-step path through descendants and another attached Smart Gallery. Attempt a physical-gallery hierarchy move and a complete drag-and-drop tree change that would introduce a loop; rejection must occur before the first filesystem move. Also exercise `sync_gallery_parent_ids()` and public-path parent repair with a filesystem-derived parent map that would introduce a Smart Gallery cycle; both must refuse before the first `parent_id` update. For a valid multi-gallery drag-and-drop whose temporary move order would create an intermediate invalid shape, verify the preflighted final map succeeds and graph-dependent reads after each committed hierarchy mutation do not reuse stale cached parents. Seed a legacy cyclic junction/rule combination in a disposable database and verify public placement skips it, Admin shows a repair diagnostic, detach remains available, unrelated Smart Galleries still work, and no request grows without bound.

For the Admin drawer, open create/edit from the Smart Gallery list and verify the right-side panel stays mounted, the browser URL does not change, Preview shows real matching cards, Save/Preview/duplicate/update-placement/remove-placement actions update the drawer in place, and rule/presentation controls still work after repeated submissions. In the physical gallery editor, save top/bottom/order attachment changes inside the existing gallery-edit panel and verify that panel also stays mounted. Repeat both workflows with JavaScript disabled and verify normal form POST/redirect behavior remains functional. Also test a local host alias/port that differs from configured `base_url` to ensure panel saves keep the authenticated session.

For downloads, enable the global downloads feature and the Smart Gallery download override, verify the ZIP contains only currently authorized matching originals, and confirm disabling either setting removes/refuses the action. Verify the independent 5,000-image guard and lower `smart_gallery_zip_max_source_bytes` temporarily in a test config to confirm cumulative original bytes are rejected before ZIP creation. Start two requests for the same Smart Gallery/signature and verify only one builder owns the final path, the waiting request reuses the completed cache after acquiring the lock, no `.partial-*` file remains after success/failure, and the final ZIP appears only through the atomic rename. Force a ZIP failure and verify the persistent log contains only the Smart Gallery ID, exception class, and stable reason code, never a raw exception message or filesystem/database detail.

### Public language preference

```powershell
php tests/public_language_preference_test.php
php tests/translation_catalog_consistency_test.php
```

These focused scripts verify that only complete maintained languages appear, each selector language maps to an existing bundled SVG flag, and the viewer selector defaults to enabled with all four languages. They also cover ordered subset normalization, rejection of an empty selection, disabled-language query rejection, complete feature disabling, query/cookie/session precedence, reset behavior, same-page links, shared Theme/Settings panel registration, and catalog key/placeholder parity.

Manual Admin regression:

1. Open Theme > Language and confirm the viewer-language panel appears beside the Admin and public default selectors with all four languages selected on an installation without explicit settings.
2. Open Settings > General and confirm the same panel structure and current values appear. Both the panel and Settings search descriptions must explicitly say that the selector is only for public viewers, that each personal choice is saved in that viewer's browser, and that it does not change the site default, Admin language, or another viewer. Search for “viewer language selector” and “viewer languages”; each result must open General and focus its owned control.
3. Disable Swedish, save, and confirm the Swedish flag disappears from public headers while Swedish remains available for Admin language, public site default, and pack editing.
4. Disable the viewer feature, save, and confirm public headers render no language buttons and `?lang=de` plus an existing public-language cookie no longer override the site-wide public language.
5. Re-enable the feature from the other Admin surface and confirm the saved language subset returns. Dynamically revisit both pages and verify their values remain synchronized.
6. Uncheck every viewer language and submit. Confirm the page shows a validation error, persists no partial selector change, and retains the rejected checkbox state for correction.
7. Confirm Settings > General shows only preset and flag design controls plus the Theme > Language detailed-settings link. Save a basic change and verify existing custom colors and dimensions remain intact. In Theme > Language, exercise Classic, Solid pills, Outline, Soft cards, and Minimal in the preview above the compact one-line controls. Change every color/range/select/toggle class of control, including each color's Transparent switch, and confirm the actual flags, code/name visibility, spacing, borders, active state, and responsive orientation update without saving or navigation.
8. Use an individual Reset and confirm only that value changes; use Reset this preset and confirm global choices plus other presets remain untouched; use Reset all and confirm Classic plus every canonical design default returns while selector enabled state and enabled languages remain untouched. Submit only afterward and reload both Settings surfaces to confirm synchronization.

## Pre-Phase-3 Viewer Feature Wrapper

Run the focused master feature regression:

```powershell
php tests/viewer_feature_wrapper_test.php
```

The wrapper test verifies that the canonical `viewer_accounts` Admin feature defaults to disabled while all established feature switches retain their historical enabled defaults. It proves that historical `config.php` or `viewer_accounts_admin_mode=invite_only` state cannot bypass the master switch, every current `viewer_*` dispatcher route and the historical Admin viewer-management route belong to the wrapper, the Admin Viewer accounts navigation item is feature-owned, public disabled viewer routes use a generic not-found result, production load order establishes feature flags before viewer services, and every feature reference used by the current Admin menu/route map resolves to a registered switch.

Manual regression:

1. On an installation with no persisted `feature_flag.viewer_accounts.enabled`, open **Admin > Features** and confirm **Viewer accounts and collections** is present under the account/personalization group and is OFF while the established feature cards retain their previous defaults.
2. With the master switch OFF, confirm public viewer Login/Account controls, favourite/collection UI, and the Admin **Viewer accounts** menu entry are absent. Direct viewer URLs must fail closed and must not advertise that the viewer subsystem exists. Existing viewer rows and content remain stored.
3. Turn the master switch ON. Confirm the Admin **Viewer accounts** menu entry appears. The subordinate Viewer Accounts registration mode remains independently configurable as **Disabled**, **Invite only**, or **Open registration** once the master switch is ON.
4. Keep the master ON but turn the subordinate viewer frontend mode OFF. Confirm Admin account creation/deletion and Phase 2.5 security controls remain available, while public viewer login remains unavailable.
5. Re-enable the subordinate mode and confirm an existing active viewer can authenticate normally. Turn the master OFF again and confirm the same credentials and any existing viewer session no longer establish `current_viewer()` authority, while Admin authentication and public gallery browsing remain unchanged.
6. Review **Admin > Features** after the registry audit and confirm the existing optional switches remain present. Core gallery/admin workflows such as ordinary galleries, Smart Galleries, tags, standard uploads, authentication, updates, integrity, and logs remain core behavior rather than being accidentally wrapped as optional features.

## Phase 1.0 Invite-only Viewer Account Boundary

Run the focused trust-boundary regression:

```powershell
php tests/viewer_http_phase10_test.php
```

It verifies the Phase 1.0 viewer/Admin account route surface, absence of open signup/collections routes, isolation of later favourite mutation logic from the Phase 1.0 account controller, Admin-only invitation mutations with Admin CSRF, scanner-safe invitation/verification/reset GET handling, viewer/pre-auth CSRF, mail-abuse authorization before verification transport, login delegation and pre-hash rate limiting, viewer/Admin session separation, POST-only viewer logout, dedicated remember-cookie rotation without recent reauthentication, no-store plus no-referrer classification, secret-bearing viewer routes bypassing the generic SEO query logger, suppression of viewer bearer URLs from the Admin-login return parameter, feature-switch gating, and unchanged gallery-authorization boundaries.

The historical Phase 0 through 0.7 tests remain active. Their route-free assertions now protect the original service-layer separation rather than incorrectly requiring the whole application to remain viewer-HTTP-free after Phase 1.0. `translation_catalog_consistency_test.php` also requires all new literal viewer UI keys to remain aligned across English, Czech, German, and Swedish.

Manual Phase 1.0 regression on an HTTPS test installation should cover this sequence:

1. Keep the master **Viewer accounts and collections** feature disabled in **Admin > Features**. Verify public galleries and Admin login behave normally, no public viewer Login entry is shown, and no Viewer accounts entry appears in the Admin Account menu. A direct public viewer route should return the ordinary not-found surface.
2. Enable the master feature in **Admin > Features**. Open **Admin > Account > Viewer accounts**, select **Invite only** with the subordinate Admin registration-mode selector, create an invitation, copy the show-once link, and verify no account row is created yet. Confirm this works even when the fallback `config.php` viewer block remains disabled.
3. Open the invitation link with GET and verify repeated/scanner-style GET requests do not consume it. Submit an incorrect bound email and confirm the public result remains generic. Submit the correct email and confirm a verification message is sent only through configured mail transport.
4. Open the verification URL repeatedly with GET and confirm no durable account exists. POST Continue, then choose a password of at least 15 characters and activate once. Replaying the original verification URL must not create a second account.
5. Log in as the viewer and, in the same browser session if desired, separately log in as Admin. Confirm `current_user()`/Admin access remains independent from the viewer account and viewer login does not unlock any password/private gallery.
6. Log out from the viewer account and confirm the Admin session remains alive. A GET to the logout route must not perform logout.
7. Log in with Remember me, allow the ordinary viewer PHP session to disappear, and confirm the dedicated viewer remember credential restores only viewer identity, rotates, and does not satisfy recent reauthentication.
8. Request forgotten-password mail for one known and one unknown email and confirm the browser-visible responses are equivalent. Repeated reset-link GETs must be non-consuming; final POST reset must invalidate old viewer sessions/remember authority without affecting Admin identity.
9. Inspect response headers for login, invitation, verification, reset, and account pages. They must be private/no-store. Public anonymous galleries must retain their existing cache/access behavior.
10. Re-disable viewer accounts and verify the viewer UI disappears/fails closed while ordinary galleries and Admin authentication continue normally.

Optional real MySQL/MariaDB concurrency coverage remains `tests/viewer_phase07_mysql_concurrency_test.php`. It is executed only when `pdo_mysql` plus `GALLERY_TEST_MYSQL_DSN`, `GALLERY_TEST_MYSQL_USER`, and `GALLERY_TEST_MYSQL_PASSWORD` are actually available. A skip must be reported as a skip, never as a pass.

## Phase 1.1 Viewer Favourites

Run the focused favourites boundary regression:

```powershell
php tests/viewer_favourites_phase11_test.php
```

It verifies the existing Phase 0 `viewer_favourites` table is reused, the mutation route is POST-only and viewer-CSRF-protected, no Admin principal/session state is used, every write delegates to `viewer_source_image_can_reference()`, owner/security-version checks and quota admission occur under the viewer-account row lock, and the controller contains no favourite SQL. It also requires the private list to re-check `viewer_source_image_can_render_reference()` before metadata rendering, optional favourite-state decoration to fail closed on viewer-storage errors, normal/Smart Gallery authorization code to remain viewer-independent, lightbox state to remain server-provided, no secret-bearing gallery return URL to be relayed through the mutation form, and keeps collection/share/profile/upload responsibilities out of the Phase 1.1 favourites service/controller itself.

Manual Phase 1.1 regression on an HTTPS test installation should cover:

1. With viewer accounts disabled, verify anonymous/public galleries, protected galleries, Smart Galleries, Admin login, and existing share links behave exactly as before and no favourite control appears.
2. Sign in as an active viewer and open a public physical gallery. Add/remove a favourite from a card, reload, and confirm the state persists. Repeat in lightbox and confirm card/lightbox state stays synchronized.
3. Repeat on an authorized Smart Gallery item. Confirm the control refers to the canonical source image and remains synchronized when lazy lightbox navigation crosses the initial page/window.
4. Open the private Favourites page from Account. Confirm only currently authorized source photos render, inaccessible saved references disclose no image/gallery metadata, and removing a visible favourite works with and without JavaScript.
5. Verify a viewer cannot add a non-public, unauthorized password/private, expired-share, or NSFW-restricted source image by POSTing its numeric image id directly. Existing Admin access in the same browser must not make that write succeed unless the non-Admin source authorization independently succeeds.
6. Verify the configured `max_viewer_favourites_per_account` limit blocks the next add without deleting/changing existing favourites. Repeated add/remove requests remain idempotent and no duplicate row can exist.
7. Hold both Admin and viewer principals in one browser. Confirm favourite controls do not overlap Admin reorder/Picture Manager controls, viewer mutations never write `user_id`, and Admin authorization remains unchanged.
8. Inspect viewer gallery/account/favourites responses while signed in and confirm personalized responses remain private/no-store. Anonymous public gallery output must contain no viewer email or favourite state and must remain operational if optional viewer favourite storage is unavailable.
9. Inspect Phase 1.1 favourites code to confirm it does not implement collection CRUD/share, public viewer profiles, uploads, comments, open signup, CAPTCHA, OIDC, TOTP, passkeys, or magic-link authentication.

The optional real MySQL/MariaDB concurrency harness remains `tests/viewer_phase07_mysql_concurrency_test.php`. It does not yet contain a dedicated favourite-quota race scenario; if `pdo_mysql` or the configured test DSN is unavailable, report that exact skip rather than claiming live concurrency coverage.

## Phase 1.2 Viewer Account Lifecycle HTTP Wiring

Run the focused lifecycle boundary regression:

```powershell
php tests/viewer_account_lifecycle_phase12_test.php
```

It loads the real Phase 1.2 controller, directly exercises the bounded recent-reauthentication destination parser, audits every imported project function against a real source definition, and verifies the exact lifecycle route surface. Static contract checks then protect viewer CSRF, GET/POST boundaries, no-store classification, password-backed recent reauthentication, remember-me exclusion, password-policy/service delegation, staged/budget-authorized email mail, scanner-safe verification GET, tokenless single-use final email POST, explicit destructive confirmation, service-owned deletion, Admin/viewer principal separation, fail-closed feature/schema behavior, translation alignment, absence of a new migration, and keeps later private-collection/open-signup/public-profile/upload/optional-auth responsibilities out of the Phase 1.2 lifecycle controller.

Manual Phase 1.2 regression on an HTTPS test installation should cover:

1. Sign in as a viewer with Remember me, expire/remove only the ordinary viewer PHP authentication state so remember restoration occurs, then open Change password. Confirm the sensitive action requires the current viewer password and a wrong password fails generically without establishing Admin identity.
2. With a normal recently password-authenticated viewer, change the password. Confirm the old password no longer authenticates, the new password does, viewer sessions/remember credentials follow the Phase 0.7 invalidation contract, favourites remain owned by the same account, and a simultaneous Admin login survives.
3. Start Change email and submit malformed, unchanged, already-used, and valid new addresses. Confirm public errors remain generic where appropriate and the current account email never changes at request time. Confirm mail is attempted only after the existing email-change mail budget authorizes/stages the request.
4. Open the valid email-change verification URL repeatedly with GET. Confirm GET never changes the durable account email. Complete any required recent reauthentication and use the tokenless CSRF-protected confirmation POST exactly once. Replay/stale/superseded/expired confirmation must fail. Confirm login moves from the old verified email to the new one according to service semantics and favourites remain attached to the same viewer id.
5. Hold Admin and viewer principals simultaneously. Repeat password and email changes and confirm `$_SESSION['user_id']`, Admin persistent login, protected-gallery authorization, gallery share grants, and Smart Gallery behavior are unchanged.
6. Open Delete account with GET and confirm no deletion occurs. After recent viewer reauthentication, submit without the explicit destructive confirmation and verify rejection. Then confirm deletion and verify viewer login/sessions/remember/reset/email-change authority and favourites are gone according to the existing lifecycle/cascade design, while the simultaneous Admin principal remains signed in and galleries/images/share links are untouched.
7. Inspect Account, reauthentication, password, email, verification/confirmation, and deletion responses. They must remain private/no-store; token/security pages must retain no-referrer/noindex behavior. Forms must contain only bounded lifecycle destinations, never arbitrary return URLs.
8. Disable viewer accounts and confirm every lifecycle route fails closed and account controls disappear with the viewer account UI. Break/withhold optional viewer lifecycle schema in a test environment and confirm unrelated anonymous public gallery browsing remains operational.
9. Confirm the Phase 1.2 lifecycle controller remains limited to lifecycle actions and does not implement collection CRUD/sharing, public profiles, comments, uploads, open signup, CAPTCHA, OIDC, TOTP, passkeys, or magic-link authentication.

The existing Phase 0.7 service/concurrency tests remain the source of truth for atomic security-version, session/remember invalidation, email replay/race, and account-deletion storage semantics. Run `tests/viewer_phase07_mysql_concurrency_test.php` when `pdo_mysql` and the configured MySQL/MariaDB DSN are actually available; otherwise report the exact `SKIP` reason.

## Phase 2.0 Private Viewer Collections

Run the focused collection boundary regression:

```powershell
php tests/viewer_collections_phase20_test.php
```

It verifies reuse of the existing Phase 0 collection schema, strict current-viewer ownership predicates, viewer CSRF and POST-only mutations, bounded integer IDs, centralized plain-text title validation/escaping, account/collection row locking around quotas, the dedicated collection-creation rate limit, duplicate-safe image insertion, transactional reorder validation, and reference-only delete/remove behavior. It also requires collection detail to batch re-evaluate every stored image through the no-admin-bypass source authorization path, checks dual Admin+viewer coexistence, confirms anonymous public gallery HTML performs no collection lookup, audits imported PHP functions against real definitions, and proves the Phase 2 collection service remains independent from the dedicated Phase 3 sharing authority while public profiles/uploads/optional auth remain absent.

Manual Phase 2.0 regression on an HTTPS test installation should cover:

1. Enable invite-only viewer accounts, sign in as Viewer A, create collections with ordinary Unicode titles and XSS-looking plain text, then verify titles render inertly. Empty, control-character, malformed-UTF-8, and over-120-character titles must be rejected.
2. Create Viewer B. Guess Viewer A collection ids from B and test GET detail plus rename/delete/add/remove/reorder POSTs. Every operation must fail generically without disclosing title, count, owner, timestamps, or item ids.
3. Add an authorized public image, repeat the add, and confirm one row/reference only. Confirm favourites are unchanged. Try a nonexistent/inaccessible image id and confirm no reference is inserted and no source metadata is returned.
4. Add a photo while its source gallery is public, then make that gallery private/password protected or expire/revoke the browser's existing source grant. Reload the collection and confirm the item disappears without title/path/thumbnail/EXIF leakage. Restore the normal source authorization and confirm the still-stored reference becomes visible again.
5. Repeat with a password-unlocked or valid gallery-share-granted source. Confirm only the canonical image id is stored and expiration/revocation of that independent source authority immediately affects collection rendering.
6. Hold Admin and viewer principals in one browser. Open an Admin-visible protected image that the viewer context cannot independently access. Confirm collection Add is absent/rejected and an already-stored item remains hidden. Viewer collection mutations and logout must not modify Admin session/authentication.
7. Fill a collection to `max_viewer_items_per_collection` and an account to `max_viewer_collections_per_account`; the next insert/create must fail without changing existing rows. Confirm the collection-creation rate bucket is enforced independently.
8. Reorder visible items repeatedly. Submit duplicate ids, an id from another collection, malformed ids, and an array above the item quota; each invalid request must leave the previous order unchanged. With temporarily inaccessible items present, confirm their hidden references keep stable ordinal slots while visible items reorder around them.
9. Remove an item and delete a collection. Confirm only collection references disappear; source images, galleries, Smart Galleries, favourites, gallery share links, and Admin authentication remain unchanged. Account password/email changes keep collection ownership, while viewer account deletion follows the existing FK lifecycle.
10. Inspect private collection list/detail/mutation responses for `private, no-store`. Disable viewer accounts and confirm collection UI/routes fail closed while ordinary anonymous galleries still render. Simulate unavailable collection schema and confirm unrelated anonymous browsing remains independent.
11. Inspect the complete route/UI surface and confirm there is no collection share/copy-link action, anonymous collection URL, public viewer profile, upload, comment, TOTP, OIDC, or passkey feature.

Optional real MySQL/MariaDB execution should exercise duplicate-add races, collection/item quota races, reorder consistency, and FK cascade behavior when `pdo_mysql`, `GALLERY_TEST_MYSQL_DSN`, `GALLERY_TEST_MYSQL_USER`, and `GALLERY_TEST_MYSQL_PASSWORD` are available. If they are unavailable, report the exact missing driver/configuration reason; do not report skipped live-DB coverage as passed.

## Pre-Phase 3 Administrator Viewer Account Provisioning

Run the focused account-management regression:

```powershell
php tests/viewer_admin_account_management_test.php
```

It directly loads the new Admin provisioning service and the real viewer account controller, verifies the migration and service-loader order, and protects the direct-create/list/delete boundary. The test requires account-cap locking, normal viewer password hashing, `must_change_password=1` on direct creation, no Admin-principal reuse, no plaintext password persistence/emailing, show-once Admin disclosure, viewer CSRF on the forced first-login POST, no-store behavior, and viewer-only deletion scope. It also verifies `current_viewer()`, normal session establishment, viewer content mutation, and remember-token issue/restore all reject the temporary-password state; successful replacement must clear the flag, increment the security version, revoke old viewer authority, reject temporary-password reuse, and only then establish normal viewer authentication.

Manual regression on an HTTPS test installation should cover:

1. Enable the master viewer feature in **Admin > Features**, then open **Admin > Account > Viewer accounts** while the subordinate viewer frontend mode is disabled. Create a viewer with the temporary-password field blank. Confirm the account is created despite the disabled frontend mode, the generated password is displayed once after redirect, and no viewer login is possible until the subordinate viewer-account mode is enabled.
2. Repeat with an explicit policy-compliant temporary password. Confirm duplicate email, malformed email, weak password, unavailable schema, and installation-cap exhaustion fail without creating a second account.
3. Leave **Send notification** enabled and confirm mail contains the trusted login URL and first-login instruction but not the generated/supplied temporary password. Deliver the temporary password through a separate trusted channel. Refresh/navigate away from the Admin page and confirm the plaintext show-once value is gone.
4. Enable viewer accounts and sign in with the temporary password. Confirm login redirects to `/viewer/first-login`, `current_viewer()` is still absent, no viewer remember credential is issued/restored, and favourites/collections/account pages cannot be used as a normal signed-in viewer before replacement. A simultaneous Admin principal must remain logged in.
5. Submit a weak replacement, mismatched confirmation, and the same temporary password; each must fail while retaining only bounded first-login authority. Submit a new compliant password and confirm the flag clears, the security version advances, old viewer session/remember/reset/email-change authority is revoked, normal viewer login is established, and the temporary password can no longer authenticate.
6. On a direct-created account, use the normal forgotten-password flow instead of completing `/viewer/first-login`. Confirm the scanner-safe reset succeeds through its existing verification path, clears `must_change_password`, and the reset password signs in normally.
7. Delete a direct-created and an invitation-created viewer account from Admin. Confirm an explicit confirmation is required and only the target `viewer_accounts` identity plus viewer-owned dependent state is removed. Photographs, galleries, Smart Galleries, gallery share links, favourites belonging to other viewers, and Admin authentication/session state must remain unchanged.
8. Hold Admin and viewer principals simultaneously, then create/delete a different viewer. Confirm Admin `user_id`, current viewer ownership, protected-gallery authorization, and existing gallery share grants are unaffected.
9. Inspect the direct-provisioning surface and confirm it does not itself add public signup, a public user directory/profile, collection-share authority, uploads/comments, TOTP, OIDC, or passkeys.

## Phase 2.5 Administrator Viewer Account Security Controls

Run the focused security-control regression:

```powershell
php tests/viewer_admin_security_controls_test.php
```

The test directly executes the existing `viewer_account_suspend()`, `viewer_account_restore()`, and `viewer_session_revoke_all()` helpers against a deterministic PDO fixture, so the core suspension/restoration/logout-all assertions are not source-only. It proves security-version rotation, session/remember/reset revocation, dormant collection-share revocation on account-state transition, restoration non-resurrection, `must_change_password` preservation, first-login limited-state invalidation, Admin `user_id` survival, matching viewer-namespace cleanup, other-viewer isolation, favourites/collections preservation, feature-disable operation, and fail-closed schema behavior. Static HTTP checks additionally require Admin auth, Admin CSRF, POST-only action placement, strict positive account IDs without overflow, SQL-free controller wiring, localized state-specific buttons, Admin audit events, and no Phase 3 route/UI expansion.

Manual regression should cover:

1. With viewer accounts enabled, open **Admin > Account > Viewer accounts** and suspend an active viewer. Confirm the row becomes Suspended, the viewer loses authority on the next request, an old Remember me cookie cannot restore the session, and any simultaneous Admin login remains active.
2. Restore that viewer. Confirm the row returns to Active but every pre-suspension viewer session/remember/reset/share capability remains invalid. Sign in normally again and confirm favourites and private collections are still present.
3. Repeat suspend/restore for an administrator-created `must_change_password=1` account. Confirm the temporary password still enters only the existing forced first-login replacement flow after restoration and cannot obtain normal viewer authority before replacement.
4. On an active viewer, choose **Sign out everywhere**. Confirm all viewer devices require a fresh login, the account remains Active, favourites/collections/password/first-login flag are unchanged, and any simultaneous Admin login remains active.
5. Keep the master viewer feature enabled but disable the subordinate viewer frontend from the same Admin viewer page. Confirm Suspend, Restore, Sign out everywhere, and Delete remain available to the administrator while public viewer login remains disabled. Then disable the master feature in **Admin > Features** and confirm the Viewer accounts Admin entry itself disappears and its route is centrally guarded.
6. Simulate missing/unknown viewer lifecycle security schema. Confirm the security mutation fails with a normal operational message while Admin authentication and unrelated anonymous gallery browsing continue.
7. Inspect the Admin page and routes to confirm there is still no Disable action, viewer impersonation, collection Share/Copy link control, anonymous collection route, public viewer profile, upload, TOTP, OIDC, or passkey feature.

No Phase 2.5 migration is expected. Optional live MySQL/MariaDB qualification should add races for suspend versus restore, suspend versus sign-out-all, suspend versus delete, and restore versus delete when the configured external harness is available; an unavailable driver/DSN must be reported as skipped rather than passed.

The optional MySQL/MariaDB harness remains conditional on `pdo_mysql` plus the configured `GALLERY_TEST_MYSQL_*` environment. A missing driver or DSN is a `SKIP`, not a pass. The normal focused/static and complete PHP suites do not require an external database.

## Phase 3.0 Unlisted Read-only Collection Sharing

Run the focused regression test with:

```bash
php tests/viewer_collection_sharing_phase30_test.php
```

The test verifies reuse of the dormant share table without plaintext storage, the existing 32-byte opaque-token/SHA-256 primitives, strict 43-character token syntax before lookup, fixed 30-day expiry, transactional one-active-share replacement, owner/current-viewer and Viewer-CSRF POST boundaries, un-rate-limited revoke, scanner-safe GET exchange, 303 token removal, isolated and capped `viewer_collection_share_grants`, per-request durable grant revalidation, live `viewer_source_images_resolve_authorized()` filtering, explicit no-Admin-bypass behavior, no collection-share hooks in direct media/gallery authorization, lifecycle semantics for suspend/restore/sign-out-all/delete, master-feature default-OFF ownership, independent share-schema failure, XSS/disclosure constraints, maintained language parity, and runtime resolution of new PHP imports. It also executes pure token/session-grant helper behavior without requiring an external PDO driver.

Manual HTTPS qualification should additionally create Share A, open it in two independent browsers/sessions, verify both exchange to token-free URLs, replace with Share B and confirm both existing Share A clean sessions fail on their next request, then revoke Share B and confirm its existing clean session fails likewise. Repeat with a public image that is subsequently made private and with a gallery that becomes password protected. The recipient must lose the item until independently satisfying the existing source-gallery authorization. In a browser that also holds an Admin principal, the shared page must still omit content visible only through Admin bypass, while normal Admin gallery/media routes retain their historical behavior. Verify renaming, adding, removing, and reordering update the shared live view without regenerating the share.

With the global Viewer Accounts master feature OFF, both raw and clean Phase 3 routes must look like ordinary not-found requests and owner share controls are unreachable. With the share table missing/unknown in a controlled schema-failure test, sharing must fail closed while private collections and ordinary public galleries continue to operate. Optional real MySQL/MariaDB race qualification should cover replace/replace, replace/revoke, replace/suspend, replace/delete-collection, replace/delete-account, and exchange/revoke. If `pdo_mysql` or the `GALLERY_TEST_MYSQL_*` harness is unavailable, report that qualification as skipped rather than passed.

## Phase 4.0 Open Registration Policy and Lifecycle Foundations

Run the focused policy regression with:

```bash
php tests/viewer_open_registration_policy_phase40_test.php
```

The Phase 4.0 test proves the backend registration policy recognizes only `disabled`, `invite_only`, and `open`, with invalid values failing closed to `disabled` and the global Viewer Accounts master switch still dominating every subordinate mode. It directly exercises invitation-backed versus open-origin classification from the existing nullable `viewer_invitation_id`, current-mode authorization for both origins, `open -> invite_only` and `open -> disabled` cancellation of only open-origin pending/email-verified rows, invitation preservation, stale-authority cleanup before re-enabling `open`, and fail-safe transition behavior when cleanup cannot complete. Static lifecycle contracts additionally require current-mode authorization in verification validation, explicit confirmation, and final activation before durable account creation; serialized policy re-check during staging admission; no new schema origin column; and no Viewer/Admin principal mixing. The historical temporary Phase 4.0 assertion that no generic route existed is superseded by Phase 4.1 only; all lifecycle/security assertions remain.

## Phase 4.1 Public Verified-email Open Registration HTTP Flow

Run the focused regression test with:

```bash
php tests/viewer_open_registration_http_phase41_test.php
```

The Phase 4.1 test exercises the actual registration service with deterministic staged-row fixtures and verifies: master OFF plus open is unavailable; disabled and invite_only do not expose generic registration; open requires secure transport plus viewer auth/registration storage; `/viewer/register` and `viewer_register` are wired; generic POST passes a null invitation and persists `viewer_invitation_id IS NULL`; the existing IP/subnet/identifier/global registration buckets are consumed; an already-sent valid token is not rotated on duplicate submission; `verification_send_count = 0` may retry; an expired sent token may rotate; invite-backed policy remains valid in invite_only and open; open-origin policy stops after a restrictive mode change; scanner-safe verification and no-auto-login remain; the Admin selector is exactly disabled/invite_only/open and uses the existing lifecycle-aware setting service; Register discovery is open-only; all four language catalogs contain the new strings; and no resend, Turnstile/CAPTCHA, public-profile, or Phase 5 route is added.

Manual qualification should keep the global Viewer Accounts master OFF first and confirm no viewer navigation or direct public registration surface is reachable. Turn the master ON and test each subordinate mode: **Disabled** should keep the viewer frontend unavailable; **Invite only** should retain login and Admin invitation registration with no generic Register link; **Open registration** should show Register on the viewer login page and anonymous public header, accept only email plus Viewer CSRF, return the same generic notice for accepted/suppressed requests, deliver neutral verification mail through the configured transport, and continue through GET validation -> explicit confirmation POST -> password selection -> durable activation -> viewer login. In open mode, verify an Admin invitation still works. After creating an open-origin staged request, switch to Invite only and confirm its old verification link cannot continue, then switch back to Open and confirm the old authority does not resurrect. Double-submit a freshly mailed request and confirm the first emailed link remains valid and no second message is generated by the duplicate path. Phase 4.1 itself intentionally introduced no explicit resend UI/endpoint and no CAPTCHA/Turnstile; Phase 4.2 adds the resend path while preserving every Phase 4.1 duplicate-submit protection.


## Phase 4.2 First-party Verification Resend and Recovery Hardening

Run the focused regression test with:

```bash
php tests/viewer_verification_resend_phase42_test.php
```

The Phase 4.2 test verifies `/viewer/resend` plus `viewer_resend_verification` dispatch, the Viewer/pre-auth CSRF/input contract, and the availability matrix: master OFF or registration `disabled` is unavailable, while `invite_only` and `open` are route-capable when transport/auth/registration storage is healthy. Per-request authority is tested independently: open-origin staging can resend only under `open`; invitation-backed staging can resend in both `invite_only` and `open`; restrictive mode changes block already-prepared open-origin delivery; cancelled/stale state does not resurrect.

The test exercises the existing `viewer_resend_verification_identifier` authorization and verifies delivery still references `viewer_mail_authorize_send()` plus the existing verification mail bucket family. It proves the Phase 4.1 primary token hash/expiry are unchanged by resend preparation, a newly prepared child token is unusable before successful handoff, successful handoff makes both A and B valid, and transport failure leaves A valid while B cannot verify. Independent A-first and B-first confirmation fixtures prove the first successful explicit confirmation transitions the shared registration request and invalidates the sibling. A historical primary-only Phase 4.1 request still validates and confirms after the Phase 4.2 child-table migration.

Static HTTP/security assertions require one generic public response for syntactically valid CSRF-valid submissions, no browser-supplied registration/origin/invitation authority fields, scanner-safe verification GET, no automatic Viewer/Admin identity establishment, no plaintext/hash disclosure, bounded child-token storage/cascade cleanup, translation parity, and no CAPTCHA/Turnstile/reCAPTCHA/hCaptcha, adaptive challenge, remote security API, Composer/npm dependency, or new runtime service.

Manual qualification should confirm a freshly delivered verification link A remains usable after requesting and receiving B, then repeat with B confirmed first. Simulate mail failure for B and confirm A still verifies. Check resend recovery links in relevant registration/verification UI only when the master feature and registration mode make resend available. For a staged open-origin request, change `open -> invite_only` and `open -> disabled` and confirm no resend message is handed to transport; invitation-backed pending staging should resend in `invite_only` and `open`. Let the whole staged request expire and confirm resend does not revive it. Every syntactically valid resend submission should return the same public notice regardless of address/account/request/limiter/mail outcome.

## Phase 4.3 First-party Adaptive Anti-automation Gate

Run the focused regression test with:

```bash
php tests/viewer_anti_automation_phase43_test.php
```

The focused test verifies that `/viewer/register` and `/viewer/resend` receive signed first-party form state and that authoritative action, nonce, issue time, expiry, honeypot metadata, and challenge difficulty cannot be tampered with, crossed between actions, crossed between PHP sessions, or replayed. It verifies the 12-entry session cap and opportunistic expiry cleanup, server-measured form age, randomized empty honeypot behavior, populated-honeypot suppression, clean challenge-free requests, repeated/fast escalation, hard limiter suppression, and existing `viewer_rate_limit_consume()` reuse through `viewer_automation_ip` and `viewer_automation_subnet`. Existing registration, resend-identifier, and verification-mail limiter families remain present and are not replaced.

Proof tests solve one real bounded SHA-256 challenge and reject expired/tampered/replayed authority, invalid proof, challenge limiter denial, and counters beyond the hard ceiling. Static JavaScript checks require the solver to remain a local `public/assets/viewer-anti-automation.js` asset using native `crypto.subtle.digest('SHA-256', ...)`, with no remote import, hashing dependency, browser-fingerprint probes, or unbounded counter loop. The no-JavaScript fallback is checked for Viewer-CSRF presentation, signed/session-bound/short-lived/single-use challenge authority, minimum server-measured challenge age, and existing local limiter use.

Controller-order assertions require Viewer CSRF and local syntax validation before the Phase 4.3 authorization call and require hard suppression to branch before `viewer_registration_request_begin()` / `viewer_registration_verification_resend_prepare()` and therefore before verification-mail authorization/transport. Challenge success still delegates to the Phase 4.0 through 4.2 services. The focused regression protects generic registration/resend results, Phase 4.2 primary token A preservation and sibling-authority independence, current-mode revalidation, invitation-authority independence, no Viewer/Admin principal creation, scanner-safe verification GET, bounded event context, maintained translations, and the zero-third-party runtime contract.

Manual browser qualification should test one ordinary registration and resend request with JavaScript enabled and confirm no challenge on the initial clean path. Submit immediately or repeat from the same client until escalation and confirm the local panel solves via Web Crypto and only then continues to the existing generic workflow. Disable JavaScript or Web Crypto and confirm the explicit local fallback becomes usable only after its server-enforced delay. Populate the randomized hidden field through developer tools and confirm the public result remains generic while no registration/resend or mail work occurs. Confirm no network request is made by the challenge page except normal same-site form/asset requests and configured mail remains the only outbound transport. Repeat open-origin mode changes and Phase 4.2 token-A/token-B verification scenarios to confirm the anti-automation gate never changes registration origin, verification authority, current-mode policy, or first-confirmed-token-wins behavior.

## Phase 4.4 Viewer Registration Security Operations and Phase 4 Closure

Run the focused regression test with:

```bash
php tests/viewer_security_operations_phase44_test.php
```

The focused test verifies that security operations remain inside the existing Admin Viewer-accounts surface and use the established `require_admin()`/administrator identity boundary without adding a public or Viewer metrics route. It verifies that no Phase 4.4 migration, metrics table, event table, limiter table, telemetry coupling, Composer/npm dependency, remote monitoring integration, CAPTCHA service, Redis/Memcached requirement, or new persistent visitor identifier is introduced.

Capability tests cover Viewer Accounts master state, all three effective registration modes, open-registration and resend availability, normalized Phase 4.3 anti-automation configuration, and `available` / `unavailable` / `unknown` storage states. Capacity fixtures verify durable account count/cap and pending registration count/cap, including aggregate open-origin versus invitation-backed staging, without adding email to the operations metrics. Unavailable storage is distinct from a real zero.

Event fixtures use fixed timestamps and prove rolling 24-hour and 7-day counts include only the Phase 4 allowlist and exclude out-of-window/unrelated events. The seven-calendar-day table groups accepted registration requests, verification messages sent, verification resend messages sent, and the documented anti-automation intervention definition (`viewer.automation_challenge_required + viewer.automation_request_suppressed`) by date. The implementation is checked for fixed aggregate SQL rather than an individual-event browser or arbitrary date/report engine.

Limiter fixtures and generated-query assertions protect policy-owned bucket selection and the current-pressure semantics. Active means `last_attempt_at` remains in the configured policy window or `locked_until` remains in the future. Locked means `locked_until > now`. Stale inactive rows and expired locks must not inflate pressure. The registration and verification-mail global-day budgets derive current usage only while `first_attempt_at` remains in the current policy window. Rendering must not call `viewer_rate_limit_consume()`, reset/delete limiter rows, or run maintenance.

Read-only/privacy assertions require the operations service to contain no registration, verification, invitation, anti-automation-ticket, or telemetry mutation path. Rendered operations HTML must not expose IP/IP hash, user-agent/hash, limiter subject hash, request id, event context JSON, verification authority, installation secret, or registration email dimensions. Historical Phase 4.1 generic registration, Phase 4.2 generic resend/token-A/sibling-authority, Phase 4.3 local anti-automation, current-mode revalidation, invitation authority, scanner-safe verification, no-auto-login, and Viewer/Admin principal-boundary regressions remain independently authoritative and are run again in the full suite.

Manual qualification should open **Admin -> Viewer accounts** as an administrator and confirm the new **Viewer security status** panel follows the existing three-state registration selector. Verify status/capacity sections, rolling 24-hour/7-day counts, the seven-day table, fixed limiter-family pressure, and both global-day budget rows. Confirm no identity dimension is shown in those new metrics. Disable or make one backing capability unavailable in a test/staging environment and confirm the affected subsection reports unavailable/unknown rather than zero while the rest of the Admin page remains usable. Repeatedly reload the page and confirm limiter attempts, verification authorities, staged registrations, and Phase 4.3 session challenge authority do not change because of observation.

Phase 4 is considered complete when this focused regression plus the historical Viewer, telemetry, translation, migration, packaging, complete PHP, and Node suites pass.

## Explicit caller contexts and temporary-file ownership

Every profile also runs `source-documentation-changed` and
`source-policy-changed` through read-only Git. The first enforces declaration
contracts against HEAD locally, or the explicit `PHP_GALLERY_SOURCE_BASE` ref;
the second checks documented uppercase definitions, recognized
operational numeric assignments and direct timer literals in runtime PHP/JS.
Missing history or failed parsing blocks the relevant gate. The policy artifact
explicitly lists unparsed formats, embedded scripts and map-entry review gaps;
passing this bounded gate does not mean the complete codebase has no magic values.
Full/release reuse those whole-tree reports for reviewed category budgets below.
Historical debt remains visible; three explicitly noisy policy heuristics remain
advisory rather than being counted as reviewed violations.

### Source debt category budgets

`source-contract-inventory` in full/release runs each existing analyzer once,
stores `source-documentation.json` and `source-policy.json`, and passes their
decoded reports to `scripts/source_contracts/debt_ratchet.php`. It stores the
evaluation in `source-debt-ratchet.json`; quick runs the fast pure ratchet fixture
and retains strict changed-source gates without a second whole-tree scan.

`scripts/source_contract_debt_baseline.json` records deterministic sorted counts,
separate reliable caps, the classifier fingerprint, and reviewed Git provenance.
Categories are report family, ownership scope, extension, declaration kind, and
rule family. Parameter-name suffixes aggregate within their documented rule.
Runtime, tests, tooling, native assets, and other source scopes partition budgets;
they do not exempt documentation or native typing. New reliable categories have
cap zero. Growth in any reliable category fails even if another category shrinks.

Only `constant.duplicate_name_review`, `policy.script_assignment_review`, and
`policy.css_duration_review` stay advisory: they represent unresolved lexical
symbols, unparsed script assignments, and visual CSS durations. Their counts and
deltas remain visible. Unknown rules/formats, incomplete reports, a missing or
malformed baseline, and classifier drift block coverage rather than silently
granting debt allowances. Supported analyzer-scope changes require deliberate
classifier and budget review.

After repairing historical contracts, use the explicit maintenance command:

```text
php scripts/generate_source_debt_baseline.php --check
php scripts/generate_source_debt_baseline.php --refresh
```

Check never writes. Refresh refuses reliable growth and can only lower caps;
disappeared categories retain zero caps. Review its JSON diff with the source
repair. Initial creation is separate: `--initialize --provenance-base=REF` requires
an absent baseline and resolves the reviewed base and current checkpoint to
immutable Git SHAs. It refuses an existing file; do not delete a baseline to
reset debt. Normal audits and CI never initialize or refresh budgets.

Both changed gates honor `--base=REF` or `PHP_GALLERY_SOURCE_BASE`, defaulting to
`HEAD` for local compatibility. Use the event-appropriate immutable comparison
base for CI or multi-commit reviews. Missing or unreadable history remains
blocked. Budget PASS does not establish semantic truth: review parameter/return
shapes, side effects, ownership, security, lifecycle, and compatibility invariants.
Preserve useful documentation when moving code; do not mass-generate comments.

### Documentation and declaration types across PHP, JavaScript and Python

Full and release audits scan the complete admitted source tree through
`source-contract-inventory`. Every quick/full/release audit strictly checks added or materially changed
named functions, methods, and classes (including interfaces, traits, and enums)
through `source-documentation-changed`. Documentation-only regressions in those
declarations also fail. Anonymous functions, closures, arrow functions, and callbacks
have no docstring contract, including callbacks assigned to variables or object
members. Variables, constants, and class properties also need no declaration
docstring. Named functions or classes nested inside callbacks remain checked.
File headers, native typing, operational-policy comments, and parser coverage
are separate contracts. The full inventory reports historical debt and enforces
reliable category caps while it is repaired; a passing task does not mean every
old function complies. Do not add fabricated descriptions or inflate budgets.

The changed-source gate also recognizes ordinary brace-bodied Bash and PowerShell
functions through a conservative, non-executing lexer. Added or changed functions
need meaningful preceding hash comments or PowerShell comment-based help; removed
comments fail even when the function body is unchanged. Quoted values, block
comments and literal here-documents do not create declarations. Unsupported function
forms, dynamic `eval`/`Invoke-Expression`, unterminated literals and unbalanced
function boundaries remain `BLOCKED`. Native-script argument/result typing and
execution semantics remain manual; the whole-tree inventory still checks their
headers only. Batch bodies remain outside declaration-parser coverage.

For named functions and methods, the shared contract is a meaningful summary, one typed and described `@param` for
each explicit parameter, and one typed `@return`/`@returns`. Missing, extra or duplicate
parameter names, missing types/descriptions, duplicate returns and definite type
disagreements are findings. Non-void returns require a description; `void`, `never`
and Python `None` may omit it. PHP arrays and JavaScript objects/arrays must describe
their members through shapes, generics or an explicit existing opaque contract.
Nested JSDoc field entries must refer to a real signature parameter. Class-like
declarations require a meaningful summary. Put documentation above its declaration;
do not insert docblocks inside argument lists or extract named helpers solely for
documentation coverage.

Use native syntax with the same contract:

```php
/**
 * Preserve the supplied selected record count.
 * @param int $count Selected record count.
 * @return int Preserved selected record count.
 */
function preserve_count(int $count): int { return $count; }
```

```javascript
/**
 * Preserve the supplied selected record count.
 * @param {number} count Selected record count.
 * @returns {number} Preserved selected record count.
 */
function preserveCount(count) { return count; }
```

```python
def preserve_count(count: int) -> int:
    """Preserve the supplied selected record count.

    @param int count Selected record count.
    @return int Preserved selected record count.
    """
    return count
```

Python also accepts typed Google-style sections (`Args: count (int): ...` and
`Returns: int: ...`, on their usual indented lines). Braced tag types support spaces
inside Python generic/union expressions. A bare prose docstring or an untyped Google
argument does not satisfy the parameter/type contract.
For generators, `Yields:` describes individual items and does not replace the
returned iterator's typed `Returns:`/`@return` contract.

PHP signatures require native parameter and return declarations. Constructor and
destructor return declarations are exempt because PHP forbids that syntax. Python
requires parameter and return annotations, including `-> None`, without importing
the scanned source or evaluating annotations. Python AST coverage includes classes,
nested/async functions, positional-only and keyword-only arguments, `*args`, `**kwargs`,
classmethods and staticmethods. Bound receivers are implicit; staticmethod arguments
are explicit. Python lambdas are excluded because Python supplies no native docstring
or annotation syntax for them. JavaScript has no native parameter type syntax, so
its declaration type contract uses standard braced JSDoc types instead.

This gate checks declarations and documentation agreement; it does not prove runtime
type correctness or documentation truthfulness. PHP primitive conflicts and known
Python builtin/generic conflicts are detected conservatively. Aliases, imported
classes, subtyping and complex JavaScript syntax still require review. Missing Python,
invalid Python source, unreadable Git history or unsupported changed source formats
block coverage instead of passing silently. Discovery exclusions continue to protect
installation configuration, runtime/generated data, vendor and agent state.
Current PHP syntax must parse successfully. Historical PHP is compared lexically,
so fixing old syntax unsupported by the current PHP version does not block its repair.

Complete value-free findings are in the audit's `source-documentation.json` and
`source-documentation-changed.json` artifacts. GitHub Actions fetches full history
and sets `PHP_GALLERY_SOURCE_BASE` to the pull request's target SHA or the previous
head for a push, covering all commits in a push. Manual runs use `HEAD^`. A clean
CI checkout therefore checks committed changes. An unavailable base (including a
root commit or unreachable force-push predecessor) blocks this comparison; choose
an available immutable comparison ref deliberately rather than bypassing coverage.
GitHub uploads both documentation reports with the compact audit summary.

For diagnosis or an explicit whole-tree migration check:

```bash
php scripts/check_source_documentation.php --strict --json
php scripts/check_source_documentation.php --changed --base=HEAD^ --json
```

Normal agent verification remains the central audit. Its PHP regression automatically
discovers `tests/source_type_documentation_test.php`, which exercises valid and invalid
contracts, signatures, safe Python parsing, source non-execution, documentation-only
regressions and CI parent-ref enforcement.

The separate `python-import-policy` suite runs in every quick/full/release profile
and forbids `from __future__ import annotations` throughout all admitted `.py` and
`.pyw` sources, including unchanged files. It uses AST import statements, so aliases,
combined imports and multiline syntax are covered; comments, docstrings and ordinary
strings are ignored. `concurrent.futures` and other future features remain allowed.
There is no Git comparison or legacy-debt exemption for this rule. Invalid source
or unavailable Python blocks coverage. The suite writes `python-import-policy.json`
with safe file/line locations; `tests/python_import_policy_test.php` protects this
policy through isolated fixtures. Python is required only for developer/CI audits,
not normal Gallery web requests on shared hosting.

The central audit discovers `viewer_anti_automation_context_test.php` and its
isolated support fixture. They execute actual registration/resend controllers and
ticket services with controlled identity, limiter, signing and mail seams:
cross-owner refusal, one-use consumption, exclusive expiry, retention, no-op
storage, challenge replacement and exception-safe publication. This does not
establish real HTTP session-handler concurrency or mail delivery.

`mobile_webdav_body_test.php` covers the actual controller/service staging path
with disposable files, stream faults and fake persistence. Late missing/unknown
schema retains staged bytes; ordinary failures clean up only the owned file.
`gallery_migration_temporary_files_test.php` checks request-owned transfer release
and ZIP contents, requiring ZIP support. Neither test uses live gallery storage.

`gallery_report_policy_test.php` exercises integer query bounds and stable output
ranking; the registered Node counterpart checks server-owned batch defaults and
browser retries. Run these through the central audit, not a separate test loop.

## Clipboard image uploads

The central quick/full audit includes `admin_upload_clipboard_test.mjs` for MIME/picker-policy filtering, optional-format capability hints, original names and bytes, distinct generated screenshot names, multi-image extraction, file-only exposure and non-image/null/empty inputs. The full audit browser suite includes `admin_upload_clipboard_browser_test.mjs`, using the confined `admin_upload_clipboard.html` fixture. Real browser FileLists, cancelable paste events and production upload handlers verify additive picker selection, native multipart/required validation, focus routing, text-editor exclusion, dynamic panel replacement, busy/disabled/hidden controls, unavailable FileList assignment, target/CSRF preservation, canonical classic completion and upload-limit failures. These synthetic events do not prove OS clipboard delivery.

Record browser/OS versions and actual results for the manual matrix below; unexecuted cells remain pending. Use a disposable migrated installation and an authenticated administrator. Repeat each case with browser preparation checked and unchecked, on the direct upload page (existing and new gallery) and in the existing-gallery/create-and-upload side panel.

| Platform | Browsers to check | Paste shortcut | Clipboard sources |
| --- | --- | --- | --- |
| Windows | Current Chrome, Edge, Firefox | Ctrl+V | Snipping Tool screenshot; copied raster image |
| Linux | Current Chromium/Chrome, Firefox | Ctrl+V | Desktop screenshot copied to clipboard; copied raster image |
| macOS | Current Safari, Chrome, Firefox | Cmd+V | Control+Shift+Cmd+4 screenshot; Preview/browser copied raster image |

1. Focus the upload area, paste a screenshot, and confirm it appears in the ordinary file selection. Select/drop an image first and paste twice; all images must remain selected. Test a named image and multiple supported image items where the OS/browser exposes them. Confirm retained names or distinct `clipboard-...` names with the correct extension.
2. On the direct page, focus each upload form and then its surrounding page; confirm only the intended form receives images. With a panel open, confirm its uploader receives them and the background page does not. Close/reopen or refresh the panel fragment and repeat paste.
3. Paste ordinary text, HTML, a URL, PDF and SVG. None may become an upload. In title/description/contenteditable fields, ordinary paste must keep editing text. Browsers that expose no image clipboard data must still allow chooser/drop uploads.
4. Submit to the selected gallery and confirm existing metadata/thumbnail processing, progress and final contents. During upload, paste again and confirm the captured selection stays unchanged. Force an ordinary upload-size/invalid-image failure and confirm existing error handling. For side-panel submission, the URL must remain unchanged, the panel stay open, and refreshed controls still accept paste.
5. With JavaScript disabled, confirm ordinary file selection and POST still work. Do not record unsupported multi-item OS clipboard exposure as a passed multi-image test; distinguish supported, unavailable and pending evidence.

## Browser upload oversized-single-image batching

For browser-assisted gallery uploads, treat the configured ZIP batch size (24 MB by default) as a soft packing target. A prepared image package is atomic because it contains the original plus all browser-generated thumbnail variants. If one package alone exceeds the target, it must be emitted as a one-image ZIP batch instead of failing. The client and server must still reject a prepared ZIP that exceeds the detected effective PHP upload limit. Multi-image batches must continue splitting at the configured target and maximum-images-per-batch setting. Run `php scripts/check_admin_mutation_contracts.php`, PHP/JavaScript syntax checks, and verify `app/core-manifest.json` after changes to this path.

## Cooperative galleries foundations

The central audit discovers `cooperative_galleries_foundations_test.php` and
`cooperative_galleries_storage_test.php`. They cover unanimous A+B+C approval,
missing friendship edges, exact consent hashes, stale revisions, generation-bound
trust, source-policy refusal and isolated credential persistence. Storage tests use
in-memory SQLite and OpenSSL, with no production configuration or live migration.
The audit registry reports missing extensions as BLOCKED. This coverage does not
claim real MySQL concurrency, networking or browser workflows.

`cooperative_pairing_workflow_test.php` adds two isolated SQLite installations running
real domain services plus real Admin controller calls with fixture authentication. It
covers reverse proof, no-consent imports, secret-free projections, lost replies, retry
backoff, expiry, narrow schema revocation, simultaneous disconnects and fresh pairing
without resetting credential generations. `outbound_http_transport_test.php` supplies
controlled DNS/cURL responses to verify pinning, TLS options, no redirect/proxy forwarding,
response bounds and safe errors without external networking. Their required extensions
are explicit in the central audit registry. Real MySQL row-lock concurrency and two
public HTTPS deployments remain deployment qualification; production deployment interactions remain separate from isolated browser fixtures.

The cooperative workflow test now also exercises real UI fragments, secret isolation,
HTML escaping and the ordinary POST fallback. `admin_cooperative_galleries_browser_test.mjs`
is registered in the central browser suite and reuses the confined drawer fixture runner.
It checks every friendship action, dynamic rerender, duplicate setup/submission, preserved
URL/open drawer/input, refresh without lost request identity, and late-response suppression
after close/reopen. It uses production browser modules and synthetic local responses;
no live gallery credentials or remote installations participate.

`cooperative_album_sources_test.php` runs source selection, canonical public export
policy, identity preparation and the read-only Admin endpoint on isolated SQLite.
It checks inherited password/visibility/NSFW restrictions without session bypasses,
changed or deleted source albums, invalid ancestry, scope normalization, pagination,
safe projection, complete schema preflight and disabled/anonymous/method refusal.
This is preparation coverage, not group approval or media-export qualification.

`cooperative_proposals_exchange_test.php` exercises initial proposal delivery and
independently owned decisions on separate A/B/C SQLite nodes plus an unrelated peer.
It covers missing direct friendship, immutable replay, lost acknowledgements, forged
claims, source and credential changes, terminal decline, concurrent response suppression,
explicitly unsupported expansion, expiry and Admin/CSRF/OFF/schema boundaries. It asserts
that all observations still leave sharing inactive. Networking is substituted in process;
live HTTPS deployments and MySQL concurrency require separate qualification.

`cooperative_activation_test.php` shares the independent-node fixture with the proposal
suite. It covers fresh nonce-bound verification, missing proofs/edges, failed retries,
partial activation, exact local metadata authority, expiry/renewal, lost-finalization
retry, local and observed remote withdrawal, concurrent decisions, changed friendship
generations, and feature/schema refusal. It also distinguishes initial proposal expiry
from renewal of already active consent. No sleeps or live remote installations are used.

`cooperative_maintenance_test.php` covers automatic single-step verification, the
active-only scheduler pass, fresh-lease no-op behavior, renewal near expiry, interrupted
round recovery, negative-consent backoff, expired evidence replacement and concurrent
withdrawal. The production Admin controller retains its canonical mutation response and
capability OFF refuses before storage. Additional cases cover concurrent transport
polling, dropped replies, crash ownership expiry, durable retry cooldown and stale
responses after a newer owner or concurrent decline. Run the central audit; no live cron or peer server
is needed for these isolated regressions.

The cooperative proposal review contracts cover no-write/no-network reads (including
empty installations), escaped local titles, visible participants and permissions, exact
digest/revision forms, UI-specific canonical completion, stale submissions and HTML
fallback. The registered Chromium fixture covers approve, decline, deliver, refresh and
advance actions with dynamic form replacement, preserved drawer/URL, error recovery and
late-response suppression. Both are run through the central audit.

The composition regression verifies that album locator codes never grant consent, require
current direct friendships, reject unknown scopes/duplicate participants and retain the
same immutable proposal on a repeated request ID. Browser coverage additionally checks
reference and composition actions, retained codes/selection across picker pagination,
preservation of a separate draft and reset only after successful creation.

The cooperative metadata regression uses only synthetic in-memory SQLite installations.
It verifies exact exported fields, no raw row/credential leakage, current public-source
restrictions, exact membership/revision binding, lease expiry, directed credentials,
Unicode title bounds, unknown schema, revocation and no-write behavior. Controller tests
cover method refusal, ignored query/form/cookie credentials and content type; no external
server or production data is used. Run through the central audit.

The public cooperative workflow is covered by cooperative_content_test.php and
cooperative_completion_test.php, automatically discovered by the central audit.
Independent SQLite installations exercise A+B to A+B+C with missing A-C friendship,
fresh unanimous decisions, fixed one-day email expiry, captured mail/cooldown,
direct withdrawal invalidation, photo pagination, hidden/NSFW/password rejection,
unknown schema and stale media tickets. No production data or mail transport is used.

`cooperative_content_admission_test.php` covers group-wide remote catalog admission,
success spacing, failed/crashed retry bounds, concurrent readers, replacement ownership
and concurrent withdrawal. `cooperative_derivative_bytes_test.php` checks real JPEG/WebP
containers, EXIF/XMP/ICC/comment removal, progressive scans, malformed/oversized inputs,
animation refusal and trailing bytes. `thumbnail_source_identity_test.php` uses actual
path helpers to check extension/path collisions, legacy compatibility, cleanup ownership,
metadata freshness and both permanent thumbnail renderers.
`thumbnail_identity_lookup_budget_test.php` exercises the real model against 10,000
unrelated rows, bounded candidate sets, restricted collisions, literal wildcard
filenames and Unicode case variants. `picture_manager_copy_test.php` verifies that
copies preserve derivative bytes and select new names without image generation.
These regressions also cover shared legacy cache invalidation before deletion/rename
and bounded refusal of changed, removed or oversized cooperative media files.

The full audit includes cooperative_gallery_browser_test.mjs for automatic renewal,
attribution, isolated failure, pagination and explicit retry in Chromium. The
proposal panel fixture checks photo scope retention, email/expansion forms and finite
automatic continuation across dynamic replacements. Both canonical thumbnail
renderer suites remain in the central audit; the new cooperative grid does not
change their rendering or lightbox contracts.

Deployment qualification still needs separate HTTPS installations, verified
migrations, generated derivatives and configured email delivery. These fixtures do
not claim SMTP inbox delivery or cross-server MySQL/HTTPS coverage. Use the central
audit rather than replacing it with focused-test loops.

### Headless installer dependency checks

The negative installed-dependency cases in `winapp/tests/test_build_installer.py` use an import-only Tkinter stub. They must verify unsupported/missing package refusal without requiring Tcl/Tk, a display server or actual build packages on the audit host. Real installer builds retain their GUI/runtime dependency preflight. These cases are registered through the central WinApp regression suite; local results do not attest a hosted GitHub Actions run.

### Compact Admin workspace coverage

The central audit registers `tests/admin_settings_workspace_browser_test.mjs` and `tests/admin_dashboard_workspace_browser_test.mjs` with isolated fixtures. These cover lazy sections, save/revert, failure/retry and panel persistence. PHP contracts cover Settings normalization/controller envelopes, deferred dashboard fragment validation/session release and indexed Overview totals. Live authenticated installation testing remains separate from these fixtures.


## Administration and orientation live acceptance

The release profile registers tree/features/password/creation/public-home/upload/Trash and Theme Appearance/Custom CSS/Language/Layout/Media browser fixtures. PHP covers subtree aggregation, plan snapshots/stale refusal, own-password/token retention, confirmed-empty onboarding, coupled upload settings, mobile one-time credential retention, CSS rollback, orientation replay and update/navdata budgets.

Live acceptance should stage nested/overlapping intentions, exercise voting/game coupling and cancel/discard, and change a gallery from another tab between review/apply. Check that rerendered controls remain intercepted and panel URL/open state remain stable. Remove an own password with token/ancestor protection and verify the retained access boundary.

Upgrade a backed-up catalog with explicit/inherited global/gallery/tag/Smart layouts and Trash. Appearance must stay equivalent after migration; retry must not swap again. Import old sidecars and restore old snapshots. Fresh setup must use vertical/photo-above-text. Exercise all-tag no-JavaScript presentation and both permanent renderers.

In Settings Uploads verify coupled limits, MB/byte mapping, disabled mobile inventory, issue/revoke, retained one-time password after failed list reads and legacy links. Test Theme pointer/keyboard resize, form-local language previews, subtab selection and CSS keep/replace/reset/refusal/recovery.

Updates must synchronize labels/notes/API diagnostics, leave concurrent requests usable, throttle auto checks and pause disabled jobs. Navdata must honor weekly freshness/backoff and atomic import. Live storage, real-browser and post-publication checks remain distinct from deterministic fixtures.

Route navigation-data coverage is part of the central audit. `route_navdata_background_test.php` covers independent due imports, saved-route completion, captured coordinate/OFP preservation, conditional model writes against concurrent edits/deletions, schema refusal and busy/backoff behavior. `admin_navigation_data_ui_test.php` checks authentication, CSRF, session release and canonical affected gallery/parent contexts. The registered `admin_update_jobs.html` browser fixture holds a navdata request open while Save completes, checks the post-save pass, dynamic SimBrief controls, busy retries, disabled capabilities and failure isolation; the URL and drawer must stay unchanged.

SimBrief route preview coverage is part of the same central audit. `tests/simbrief_description_model_test.php` checks complete endpoint routes, runway-token identity, filed instructions, coordinate-name fallback and long descriptions across all four languages. `tests/simbrief_route_preview_test.php` exercises the production draft endpoint for both new and existing galleries, no premature persistence, private OFP preservation and geometry retention during attachment. The registered `gallery_creation.html` fixture delays an import while route text changes, verifies every draft field remains untouched, then imports and saves through consecutive dynamically replaced production editor forms with an unchanged URL and open panel. For manual acceptance, import a long route into a new and an existing gallery, confirm both airports and staged status, edit the route during a delayed response, and save/reopen to verify route text, original OFP geometry and repeated panel controls.

The registered `tests/simbrief_ofp_lightbox_browser_test.mjs` browser fixture exercises the separate, authorized OFP PDF viewer. It asserts that a portrait A4 page opens fully inside a landscape scroll stage, with centered page bounds and no overlap from the toolbar, then checks explicit fit-width scrolling, 100% actual size, and mode state. It also checks per-page mixed orientations, manual zoom/pan retention during ordinary resize, reset and width/page refitting after manual pan gutters, automatic refitting after viewport rotation and fullscreen change, all four supported labels, keyboard isolation from gallery photos, original download URL, and cleanup. The fixture stubs PDF.js, so release acceptance must additionally exercise a real multipage PDF on a configured host. On failure, the required Chromium GitHub Actions job retains its per-fixture diagnostic output in the `required-chromium` artifact (`browser-map.log`), alongside the central audit summary.

OFP fullscreen/browser acceptance must assert that the Fullscreen API targets the non-dialog document-and-HUD root, never `<dialog>` itself. It checks **F** and the visible fullscreen control for native entry/exit, **Escape** exit-before-close behavior, editable field and modified/repeated keyboard isolation, Fullscreen API denial with CSS fallback, and native browser fullscreen exit. Wheel tests must assert that **ordinary wheel** and both-axis **two-finger trackpad scrolling** remain native PDF stage scrolling at any fit/zoom level, even for large mouse-wheel deltas, without changing scale. **Ctrl+wheel or trackpad pinch** zooms only PDF content around an off-center pointer in both normal and fullscreen views; Command+wheel remains browser-owned. Check zoom limits, CSS/native fullscreen, stable toolbar scale/position, no lost pan after fit/page/resize transitions, active/disabled mode affordances and WCAG AA text contrast under deliberately unreadable gallery colors. HUD controls must fade after inactivity, reveal on pointer or keyboard activity, remain clickable over the PDF, and avoid resizing or rerendering the page when shown/hidden. For release acceptance additionally verify real PDF.js output, real-device trackpad/touch pinch, different browser fullscreen permissions, narrow mobile wrapping, and focus accessibility; the Chromium fixture uses a mock PDF.js backend and synthetic fullscreen transitions.

### Saved OFP anonymous/public media delivery

The central PHP audit includes `tests/simbrief_ofp_public_http_test.php`: it launches a disposable loopback
PHP server for the real PDF media controller and checks **zero-cookie anonymous GET**, HEAD and download for
public, unpublished, access-listing-unlisted and legacy draft galleries, legacy `media&ofp=1` links,
password/share/NSFW grants, admin vs anonymous-preview authority, inaccessible private galleries,
generated private page galleries, invalid/missing PDFs and root/subdirectory `index.php` URLs without rewriting. The separate public-media authorization contract invokes the PDF access helper
against the actual gallery policy. These server-side tests do not replace hosted browser acceptance.

On the deployed host, attach a real saved multipage SimBrief PDF to a **published, public gallery** that has
no password/NSFW gate. In a fresh Chrome/Safari private window with no session cookies, open the gallery and
activate View OFP, Download OFP and the native fallback separately. In DevTools, confirm the same-origin
`?page=gallery_ofp_pdf&id=…` request returns 200, `application/pdf`, locally saved PDF bytes and
`Content-Disposition: inline` or `attachment`; HEAD has metadata without a body. Verify that neither
an Admin session nor a warm browser cache is required. Then repeat in a nested gallery and a deployment
mounted below `/galerie/` without URL rewriting. A configured absolute `base_url` on a different host
must never move the PDF fetch or its cookies to that host.

Repeat with an unpublished source gallery in a **fresh anonymous window**: View, Download, HEAD and the old
`media&ofp=1` URL must work there exactly as for a published gallery. Next repeat with a truly private
source gallery, a generated private OFP child, a password-protected published/unpublished parent before and
after a visitor unlock, a share-token grant, an NSFW gate, and an absent or invalid saved PDF.
An unauthorized request or guessed private ID must return an opaque uncached `text/plain` 404,
not a theme-rendered HTML page or a response containing a server path, remote SimBrief URL or token.
Authorized visitors retain inline and download access; verified Admin users may inspect their private
originals outside anonymous preview. The old `?page=media&id=…&ofp=1` form must still work.

Record the actual HTTP status, content type, request path and cookie context if a hosted setup still reports
`PDF not found`. A synthetic fixture PASS is evidence of the repository logic, not proof that a particular
shared-host configuration serves the route correctly.

The registered legacy-dispatch model test verifies that a reviewed cruise level of `350` becomes the documented `fl=FL350`, including leading-zero normalization, the FL600 bound and omission when blank. The drawer lifecycle fixture waits up to two seconds for the current metadata-organizer completion and actual form replacement instead of assuming completion after 150 ms; its result-row, ownership, open-panel and unchanged-URL assertions remain mandatory.

For external historical-date acceptance, open the reviewed SimBrief redirect in an authenticated provider session and inspect the departure date before generating. An unsigned browser check on 2026-10-08 reached the login gate with `date=03JUN26`; it did not establish whether the provider accepts or normalizes that date. Record the actual date displayed after sign-in, without rewriting the gallery date or describing a regenerated OFP as an original archive.

### OFP UI and real-device gesture acceptance

The description displays a compact View/Download row followed, for authorized administrators only, by a separate
Create private OFP subgallery action. The explanatory question-mark is localized in EN/CS/DE/SV, reveals on pointer
hover, keyboard focus or tap, and dismisses after click-away or Escape. Verify unavailable conversion leaves the
read-only PDF controls operational and that submitting twice cannot create duplicate galleries.

In Chromium/Edge, Firefox, macOS Safari and mobile Safari/Chrome, open the same multipage PDF in normal lightbox
and fullscreen (native where supported, CSS fallback otherwise). Test both portrait and landscape pages and
320px/375px/768px layouts with a high-contrast and an intentionally unreadable user theme. Labels, 100%, Fit width,
Fit whole page, Actual size, download and close must remain legible, keyboard reachable and within viewport. Check
that the PDF can scroll vertically and horizontally after zoom/fit-width while the header/toolbar and page itself
remain at fixed UI scale.

With a physical mouse, ordinary wheel scrolls PDF, never zooms. With a MacBook trackpad, two-finger scroll pans
the document; spreading/pinching fingers zooms it without any full-page/browser zoom. Ctrl+mouse-wheel is an explicit
zoom fallback; Command+wheel is reserved for browser behavior. Repeat at scale limits, over the toolbar and outside
the PDF stage. On iPhone/iPad Safari, iOS Chrome and Android Chrome, a single finger pans the PDF and two fingers
pinch; lift one finger during a pinch and continue panning with the remaining one, then cancel/retry the gesture.
No photo navigation, page scrolling behind the modal, accidental form activation or detached HUD is acceptable.

These are **manual acceptance requirements**, not claims that synthetic headless Chromium tests exercise genuine
trackpad, Safari, touchscreen or hosted Imagick behavior.

Windows output is `winapp/dist/<version>/PHPGalleryUploader-<version>-Setup.exe` plus generated `winapp-update.json`. Build verification checks installed dependencies, bundled SimConnect and copied-helper `--self-update-smoke`. `PHP_GALLERY_NATIVE_UPDATE_SMOKE=1` enables the central audit's read-only native version/process fixture. Installed update/UAC/restart and FS2020/FS2024 remain target-PC acceptance.

Browser harnesses create a fresh owned blank tab, attach their private DevTools session, activate that owned headless page's focus, and navigate the localhost fixture exactly once. This avoids Windows Chromium command-line startup stalls without reloading an authenticated workflow. Browser sandboxing, loopback confinement, private profiles, required-mode failure and all assertions remain enforced.

The DEV-dashboard browser fixture counts initiated media requests at its owned loopback server. Its idle check retains the original observation interval and fails on a new request, while completion of a transfer initiated before that interval cannot create a false positive. The counter endpoint is fixture-only, read-only and uncached.

The gallery-description layout compatibility fixture injects a first rename refusal deterministically. It checks bounded Windows recovery, unchanged non-Windows single-attempt refusal, refusal to overwrite an intervening edit, read-only document preservation and owned staging cleanup, alongside its existing replay and database rollback checks.

The gallery-tag fixture observes Escape dismissal during the trusted key event itself, after the production handler. This checks immediate closing state, collapsed ARIA state and summary focus without depending on a protocol response arriving before the animation finishes; final close and geometry assertions remain separate.
