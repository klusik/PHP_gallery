<!--
Project: PHP Gallery
Repository: https://github.com/klusik/PHP_gallery
File: docs/SOURCE_CONTRACT_INVENTORY.md
Module Type: Source Review Inventory
Purpose: Record source coverage, remaining documentation debt and reviewed MVC ownership.
Responsibilities:
  - Preserve actionable counts, provenance exclusions and verification limitations.
Author: Rudolf Klusal
-->

# First-party source contract inventory

This records bounded inventory/enforcement work for retained priorities 12–15.
It is not an assertion that all documentation, constants or MVC debt is resolved.
Scans inspect source without executing application files, opening live
configuration, querying a database or mutating gallery data.

## Measured snapshot and completion status

The 2026-09-20 working-tree observation below was collected during concurrent
runtime integration. Reproduce current path/line/rule evidence with JSON output
from [check_source_documentation.php](../scripts/check_source_documentation.php)
and [check_policy_constants.php](../scripts/check_policy_constants.php).
Counts are observations, not acceptance thresholds or an allowed-debt baseline.
The earlier whole documentation/constants/header snapshot below covers 997 files;
later scoped gate checkpoints have separate counts stated below. These observations
reflect concurrent edits, not exclusions. Historical whole-tree counts are not
recomputed merely because a bounded changed gate improves. Parent's earlier
Quick5 artifact (20260920-014136-24140) reported 317 changed findings and
10,915 documentation / 634 policy findings; subsequent owner remediation and
proven parser corrections are reflected in this later source-only observation.

| Priority | Proven bounded result | Remaining work |
| --- | --- | --- |
| 12 constants | Whole-source discovery, lexical definition/consumer evidence and candidate checks implemented. | 618 findings; semantic ownership, consumer tracing and runtime migrations remain. |
| 13 headers | Zero header findings in 997 first-party native source files; all seven fields enforced. 191 source paths received leading-comment-only repairs. Parent reports Quick5 header/scanner checks passed. | Final central full audit belongs to parent; five upstream SVGs retain explicit original provenance. Metadata/binary attribution is not falsely recast as source comments. |
| 14 MVC | All 27 initial advisory rows reviewed; parent resolved three persistence, four controller-filesystem, five service-session and one security-filesystem rows. Narrow SQL/PDO and converted-service session guards are fixture-tested; source inspection finds zero strict violations. | 14 advisory rows remain, including legitimate adapter/infrastructure classifications and deferred moves. This is not completion of every MVC migration. |
| 15 documentation | Token-aware declarations, typed contracts, shapes, boilerplate guard and changed-declaration enforcement are fixture-tested. | 10,497 findings in 696 files, plus disclosed semantic/language automation gaps. No bulk boilerplate remediation. |

| Source type | Files |
| --- | ---: |
| PHP, including runtime, migrations, scripts and tests | 843 |
| JavaScript .js / .mjs | 75 / 29 |
| CSS | 26 |
| Python .py / .pyw | 9 / 1 |
| Shell / PowerShell / batch | 2 / 1 / 3 |
| HTML / YAML / TeX / Apache .htaccess | 4 / 1 / 1 / 2 |
| Total | 997 |

Discovery also supports .cjs, .psm1, .cmd, .sql, .yaml, .htm and first-party .svg;
this snapshot contained none of those additional variants. It visits the entire
source tree, not selected example features. Whole-source discovery does not
establish complete language or semantic coverage.

| Declaration category | Detected declarations |
| --- | ---: |
| PHP named functions/methods | 6,494 |
| PHP callbacks/arrows | 1,016 |
| PHP classes and properties | 72 / 105 |
| JS named functions/methods | 1,300 |
| JS bound callbacks / inline callbacks / classes | 167 / 1,130 / 5 |
| MJS named functions/methods | 168 |
| MJS bound callbacks / inline callbacks / classes | 90 / 177 / 16 |
| HTML script functions / bound callbacks / callbacks / classes | 37 / 13 / 30 / 1 |

The remaining **10,497 issue occurrences across 696 files** contain no header
findings:

| Rule family | Findings |
| --- | ---: |
| Missing declaration documentation | 2,426 |
| Empty or recognizable boilerplate summary | 273 |
| Missing parameter / return tags | 2,826 / 3,264 |
| Primitive parameter / return type disagreement | 133 / 66 |
| Unspecified PHP/JS array/mixed/object shapes | 1,462 |
| Property type missing | 13 |
| Parameter description / duplicate / extra / destructuring review | 12 / 2 / 17 / 1 |
| Named parameter tag missing its type | 2 |

Largest observed queues: public lightbox JS (415), smart_galleries service (218),
viewer_registration model (150), admin-side-panel JS (125), admin-gallery-list JS
(121), thumbnail_format_metadata_consistency test (109), admin_telemetry view
(106), gallery_download_controller test (101), gallery_trash model (97), telemetry
model (89), smart_galleries model (88), download_artifact_cache service (88).
Counts overlap; a declaration can trigger several rules. Fix contradictory
primitive tags in a separately owned bounded batch, then missing typed parameters
and lifecycle/data-shape contracts after reading their implementations. Do not
infer side effects or caller guarantees merely to force the report to zero.

## Discovery, attribution and provenance

The shared owner is
[scripts/source_contracts/inventory.php](../scripts/source_contracts/inventory.php).
Directories are pruned before recursion; symlinks are never followed.
Installation/private exclusions: config.php, .env-prefixed files, custom.css,
cache, data, galleries, logs, tmp and generated HTTP monitor logs. Generated and
third-party tree exclusions: deploy, vendor, node_modules, Python
bytecode/caches/virtual environments. Tool-state exclusions: Git, IDE and
documented agent-local folders. The JSON exclusion list records encountered paths;
their contents are neither read nor counted as first-party source.

The exact upstream SVG exclusions are not a first-party header allowlist:

| Paths | Existing provenance evidence |
| --- | --- |
| public/assets/flags/cz.svg, de.svg, gb.svg, se.svg | lipis/flag-icons v7.2.3 pinned in README.md; public/assets/flags/LICENSE.flag-icons.md retains upstream attribution. |
| public/assets/link-icons/brands.svg | Bootstrap Icons derivation in public/assets/link-icons/README.md; LICENSE.bootstrap-icons.md retains upstream attribution. |

No vendor SVG, license or legal notice was rewritten. First-party SVGs outside
these five exact paths still require native headers. YAML/HTML/TeX and Apache
.htaccess are now discovered and enforced, with prolog-aware native comments.
The two new Markdown guides carry HTML author metadata before prose, verified by
the new fixture suite. Existing Markdown, JSON, .gitignore, text manifests,
dependency lists, LaTeX build products, PDFs and binaries are path/extension
accounted metadata or artifacts, not sources into which invalid comments are
injected. Generated monitor HTML remains under its runtime-output exclusion.
Embedded languages and binary provenance remain manual review. AGENTS.md was
read but not edited.

Every repaired header uses the exact file identity and a reviewed existing
purpose or module-specific responsibility; no function contracts were generated
from filenames. Atomic leading-comment patches preserve executable bodies and
licenses. Before/after non-comment token hashes matched for 124 PHP/JS repairs.
Three concurrently edited workflow files changed their bodies through other
agents; this worker's patch touched only their headers, so whole-file
byte-equivalence is not claimed. CSS/Python/batch/markup repairs likewise contain
only leading-comment hunks. The copied File value in base.css was corrected.
usage.js and telemetry.js now share the exact two-role identity documented in
CODE_DOCUMENTATION.md. Their bytes match after the header/line-ending repair;
the runtime photo-open state machine and activation vocabulary were unchanged.

## Declaration and docstring coverage limits

PHP uses token_get_all and delimiter pairing: attributes, by-reference returns,
classes/interfaces/traits/enums, constructors/private methods, assigned/inline
callbacks and class properties are inventoried. Promoted fields use constructor
parameter documentation. Anonymous declarations inside default expressions,
enum case field semantics and alias resolution need further coverage.

JS/MJS/CJS use a conservative lexer masking comments, literal chunks and common
regex. It recognizes exports, generators, methods, classes, bound arrows,
callbacks and nested template-expression code. Executable inline HTML scripts
are also scanned. It is not a full ECMAScript parser: computed/private fields,
ambiguous regex and destructuring semantics need review. Complex types and aliases need richer
parsing; primitive disagreement detection is not a general type checker.
Python/PowerShell/shell/batch declarations and data shapes are not yet parsed.
Non-callable config/markup receives native header checks. HTML inline JavaScript
is scanned; unknown script languages block the changed gate. CSS has no
application-callable contract.

The guard rejects a known generated “Handles ... logic for the gallery
application” summary and detects named parameter/return contradictions. It cannot
establish truthful side-effect descriptions, complete exception contracts,
sensitivity or comprehensive record field semantics from comment presence.
Priority 15 therefore remains substantial semantic work, not a mechanical
autogeneration task.

## Constants: owners and remaining work

The policy snapshot found **438 uppercase PHP/JS definitions** and **618 findings**:

| Rule family | Findings |
| --- | ---: |
| Definitions missing explicit type/units/scope/consumer/rationale fields | 325 |
| Duplicate-name occurrences needing scope/guard review | 30 |
| Named numeric assignment candidates | 89 |
| Literal timer-call candidates | 61 |
| CSS duration candidates | 71 |
| Script assignment candidates | 42 |

Categories overlap. Duplicate names do not prove duplicate policy: namespaces,
classes, guarded compatibility definitions and separate JS modules require
tracing. Lexical consumer paths provide starting points, not a resolved
dependency graph. Zero/one counter initialization is excluded from assignment
candidates; literal timer arguments remain reviewable. Numeric values, source
snippets and string contents are never emitted.

| Policy family | Owner and required migration decision |
| --- | --- |
| Deployment-tunable runtime budgets | configuration_defaults.php via cms_runtime_limit(); retain administrator overrides and bounded validation. |
| Immutable protocol/schema/security vocabulary | app/policy_constants.php, Gallery\\Core namespace; parent owns active runtime migration. Do not copy invariants into writable defaults. |
| Browser policy | Existing prepared view models and focused module definitions; expose only required public values and trace fallbacks to their server owner. |
| Early runtime and standalone tools | Minimal immutable interface without configuration/bootstrap dependencies; developer-only output constants stay with their CLI owner. |
| CSS durations | Review visual policy and reduced-motion semantics, not every numeric style value. |
| Python/PowerShell/shell/batch | Assignment candidates only; native parsing and consumer tracing pending. |

Multi-constant statements, lower-case names, dynamic define names, configuration
map entries and repeated semantic strings require further inventory semantics.
Existing P1/P2 implementation documentation remains visible debt without a
baseline exemption. No magic-value-free claim is made. Remediation must identify
actual scope/units/rationale, preserve live configuration and never expose
secrets. No numeric ONE/TWO constants were introduced. Parent migrated seven
ADMIN_LOG_* and five discovery policy constants, Google OAuth invariants,
ten duplicate-detector constants (including token entropy), and the report-job
family to Core with their consumers and semantic documentation. This worker did
not duplicate those definitions.

## Changed runtime policy gate checkpoint

The separate --changed --json CLI is implemented and parent registered it as
source-policy-changed in every central profile. Integration quick audit 8 exited
**1 / FAIL: 36 missing-field findings on 10 Core definitions**, with zero
blockers, zero doc-only regressions and zero new assignment/timer failures.
The bounded scan covered 537 runtime PHP/JS files from 1010 discovered native
sources: 132 byte-changed runtime files, 147 added policy sites, 0 changed,
390 unchanged and 0 moved. These counts are not a legacy-debt baseline.

All findings belong to app/policy_constants.php:

| Definition | Required explicit fields |
| --- | --- |
| GALLERY_EDIT_LOCK_WAIT_SECONDS | units, rationale |
| ADMIN_OPERATION_KEY_BYTES | units, scope, consumers, rationale |
| ADMIN_OPERATION_RESPONSE_MAX_BYTES | units, scope, consumers, rationale |
| ADMIN_OPERATION_INPUT_MAX_BYTES | units, scope, consumers, rationale |
| ADMIN_OPERATION_MAX_FILES | units, scope, consumers, rationale |
| ADMIN_OPERATION_DIAGNOSTIC_MAX_FILENAMES | units, scope, consumers, rationale |
| ADMIN_OPERATION_DIAGNOSTIC_FILENAME_MAX_BYTES | units, scope, consumers, rationale |
| ADMIN_OPERATION_DIAGNOSTIC_MAX_COUNT | units, scope, consumers, rationale |
| ADMIN_OPERATION_PENDING_PAGE_SIZE | units, scope, consumers, rationale |

Several definitions already contain useful prose; these are missing recognized
contract fields, not proof that the comments contain no explanation. Parent owns
their runtime-source annotations. The parent subsequently added each listed field
with reviewed scope, units, consumers and rationale; final qualification is
recorded in TEMP 02. No value or initializer was changed by this remediation.

The 548 coverage-review records include out-of-scope tooling/test paths,
unsupported native formats, embedded scripts and unclassified configurable-map
entries. They are not strict findings or claims of complete semantic coverage.
Duplicate ownership, dynamic definitions and broader numeric/string semantics
remain separate review work. Exact rules and report shapes are documented in
CODE_DOCUMENTATION.md; whole-tree policy inventory remains advisory and separate.

The first-definition/header and valid PHP-interpolation false blockers are fixed
with disposable regressions. Fixture tests also prove missing history blocks,
explanation removal fails, field labels cannot borrow one another, copied sites
remain new, and unchanged moves remain legacy. The flat JavaScript tuple addition
fixes the observed side-panel false positives while leaving unsupported patterns
explicitly reviewable. No active parser blocker remains from these reproduced
cases. Central full qualification remains parent-owned.

## MVC advisory ownership review

All 27 original rows below were inspected in source context. Three persistence,
four controller-filesystem, five service-session and one security-filesystem rows
are resolved by parent migrations; **14 advisory rows remain** across 467 inspected
runtime files.
Counts in the table are original token signals, not regression-test totals.
Source inspection after promotion found **zero strict violations** and no
persistence advisory row. The zero-entry baseline was not edited or refreshed.

| Source and signal count | Observed owner and required disposition |
| --- | --- |
| services/translations.php — session 20 | Language preference/context writes belong in the bootstrap/request adapter; service returns language selection and transport intent. Preserve separate Admin/public preferences. |
| services/admin_gallery_discovery.php — former session 17, resolved | Service start/process/status/read/write/cleanup now accept the caller-owned jobs map by reference. Three controller callers own the session boundary; service scan/ranking/normalization remains in place. |
| services/duplicate_photo_detector.php — former session 17, resolved | Parent moved all detector job lifecycle/pruning to explicit caller-owned jobs maps by reference. admin_duplicate_photos_job_store is the controller's by-reference session adapter. Parent reports isolation, empty completion, expiry, retention and adapter fixtures passed; the narrow service session guard now applies. |
| security.php — request 16 | CSRF, content negotiation, cookies and request identity are compatibility HTTP adapters; extract explicit adapter implementations while retaining call wrappers. |
| services/navigation_data.php — session 15 | OAuth/PKCE and persisted-token session mirroring need an HTTP/session adapter; keep schema preflight before storing token state and narrower revocation policy. |
| security.php — session 13 | CSRF and administrator identity lifecycle belong in the same HTTP/session adapter; preserve rotation and remember-cookie semantics. |
| diagnostics/admin_test_run_early.php — request 11 | Deliberate pre-bootstrap diagnostic request capture. Retain minimal infrastructure ownership; ordinary domain services cannot be loaded here. |
| helpers_page_rendering.php — request 7 | Header/footer/i18n rendering currently discovers request context. Prepare context at controller/bootstrap boundary and pass it into rendering. |
| security.php — former persistence 7, resolved | Parent moved current_user and cms_admin_user_exists through auth_accounts service to the authentication model, preserving optional-email schema decisions. Narrow core persistence guard now applies. |
| services/viewer_anti_automation.php — former session 7, resolved | Register/consume/prune and authorization now accept caller-owned ticket context; controller publishes changes through its session boundary. Parent reports the 101-assertion context fixture and Phase43 checks passed. The narrow session guard now applies. |
| helpers_mutation.php — former persistence 6, resolved | Parent moved physical-card context counting through gallery_lookup service to the galleries model. Inputs are semantic gallery scope and listed-access booleans, not SQL fragments. Narrow core persistence guard now applies. |
| helpers_mutation.php — request 6 | admin_wants_json is HTTP negotiation. Keep compatibility function, delegate request extraction to the shared HTTP owner. |
| services/google_auth.php — former session 6, resolved | Parent moved session ownership to the controller. authorization_url accepts mode, sanitized return, caller-owned states and optional user ID; consume_state accepts caller-owned states. Service retains expiry/one-time consumption; Core owns endpoint/TTL/random-count invariants. |
| services/gallery_access.php — session 5 | NSFW acknowledgment and timed gallery grants need request/session context adapter. Authorization policy remains in gallery_access. |
| controllers/gallery_migration.php — former filesystem 4, resolved | Parent moved all four cleanup calls to the migration module's owned temporary-file allocator/releaser. Outgoing ZIPs retain the reserved allocation path; role prefixes are Core policy. Parent reports ownership, unknown/repeat release and extension-free ZIP fixtures passed. |
| helpers_runtime.php — session 4 | Flash storage is an HTTP/session adapter, distinct from pure helpers. Preserve one-time read behavior. |
| controllers/admin_theme_actions.php — former filesystem 3, resolved | Parent moved CSS reset/copy/upload and optimized-background deletion into existing custom_css/gallery_backgrounds services; controller keeps request/auth/CSRF and response ownership. |
| helpers_runtime.php — request 3 | Asset base URL and method detection are request adapters. Accept normalized context for pure URL construction. |
| services/admin_gallery_report/job.php — former session 3, resolved | Parent moved start/process/read/write/clear to a nullable caller-owned checkpoint. Controller finally publishes or unsets the checkpoint after response/exception flow; parent reports the isolated lifecycle fixture passed. The entry/part include contract remains intact; the narrow job-part session guard now applies. |
| controllers/admin_logs.php — former filesystem 2, resolved | Parent moved cleanup into the logs service owned-request registry. Controller retains download headers and response streaming. |
| controllers/mobile_webdav.php — former filesystem 2, resolved | Controller opens/closes the request stream and delegates body storage to mobile_webdav_store_put_stream. The service allocates, verifies and cleans up its own temporary file after existing preflight; parent reports its isolated body fixture complete. The narrow controller filesystem guard now applies. |
| diagnostics/admin_test_run_early.php — filesystem 2 | Deliberate minimal early-failure sidecar persistence before bootstrap; retain infrastructure role, bounded safe state and failure isolation. |
| helpers_runtime.php — former persistence 2, resolved | Parent moved unique_slug suffix orchestration to the existing gallery editor service; its documented collision callback delegates to the model and preserves the caller's PDO connection. Narrow core persistence guard now applies. |
| security.php — former filesystem 2, resolved | Setup-lock compatibility facades lazily delegate to auth_accounts. Parent preserved config-and-marker semantics and UTC marker payload; failed storage now refuses explicitly. The isolated copied-service fixture passed per parent. Narrow security filesystem mutation enforcement now applies. |
| security.php — presentation 2 | JSON responses in CSRF/Admin failure are HTTP boundary output, not view HTML. Retain their response behavior in the extracted request adapter. |
| helpers_runtime.php — response 1 | redirect_to is an HTTP response adapter; preserve its terminating return contract. |
| integrity.php — filesystem 1 | Integrity cache publication is filesystem orchestration; isolate cache writer while keeping verification/bootstrap dependency requirements explicit. |


The promoted strict scope is app/security.php, app/helpers.php,
app/helpers_*.php and their split parts. Rules core.direct_db,
core.pdo_construction, core.pdo_method and core.sql_literal reject direct database
calls, PDO construction/operations and recognized SQL strings. Comments and PDO
parameter types are not violations; HTTP/request/session/response behavior is
not broadly reclassified. Database infrastructure is outside this narrowly
migrated scope. Existing central MVC registration automatically discovers it. The converted
admin_gallery_discovery, google_auth, duplicate_photo_detector and viewer_anti_automation services,
plus admin_gallery_report/job.php and future job split parts, reject direct
session globals. Dedicated rules are services.discovery_session_global,
services.google_auth_session_global, services.duplicate_detector_session_global
services.report_job_session_global and services.viewer_anti_automation_session_global.
Other report siblings are not silently promoted. Other service request globals were already strict; these session rules
are not a broad adapter ban. core.security_filesystem_mutation protects the migrated
security facade and future parts; controllers.mobile_webdav_filesystem_mutation
protects that converted controller and future parts. Read-only probes, request
stream handling, inert comments and member calls are outside the direct-write
rules. Their filesystem services remain allowed owners.

Disposable fixtures prove discovery, all four rules, split-part classification,
qualified calls, comment shielding, legitimate adapters and infrastructure
exclusion. This token-based rule does not resolve dynamic calls, imported aliases
or object types; method-name/SQL-string heuristics still require human review.
The 14 advisory decisions are not new baseline exceptions, nor a claim that all
remaining session/filesystem ownership has been migrated.

## Verification and integration handoff

The only test commands run by this worker were the newly developed
`php tests/source_contract_inventory_test.php`,
`php tests/source_contract_changes_test.php` and
`php tests/policy_constants_changes_test.php`, during fixture development;
all three passed their last run. They cover discovery, provenance/native header
formats, missing/borrowed fields, declarations, signature disagreement,
boilerplate summaries, strict exit behavior, value redaction and narrow core MVC
enforcement. Disposable files are created in a random system-temp directory and
removed by the test. No live configuration/database is used.

Existing header and named-callable tests now share discovery; the header test
enforces all seven standard fields. Existing suites were not individually run.
Read-only scanner/API inventory is source inspection, not a regression/syntax
audit. Parent reports the four strict/header/scanner suites passed in Quick5;
that audit predates the final report/detector guard fixtures and parser fixes.
Parent remains responsible for the authoritative central full audit.

Exact integration needs:

1. No additional registration for the three new PHP fixture tests: central PHP
   regression already auto-discovers them.
2. Existing MVC audit registration automatically runs the new strict scope; no
   extra duplicate MVC task is needed.
3. Parent has registered both whole-tree inventory CLIs in every central profile
   with full JSON and advisory counts, plus source-documentation-changed as a
   strict PASS/FAIL/BLOCKED task storing source-documentation-changed.json.
   Parent also registered source-policy-changed in all profiles through the
   generic changed-source adapter. No further registration is needed for this slice. Whole-tree inventory exit
   zero means the report ran, not compliance. Whole-tree --strict currently
   fails; do not baseline the debt.
4. Parent owns ARCHITECTURE.md, CODEMAP.md and TESTING.md integration plus the
   managed-file manifest refresh after all source edits. This worker changed no
   TEMP plan, core manifest, release metadata, version, Git index, commit or tag.

## Changed-declaration gate remediation queue

The earlier worker checkpoint was **FAIL: 5 findings**, with 827 added, 211
materially changed and 2607 unchanged declarations across 997 discovered /
307 byte-changed source files. All five findings belong to already assigned
runtime view owners; there are no remaining test, script, controller, service
or public-asset findings in this changed-declaration observation. This is not
a claim that unchanged legacy declarations comply.

| Assigned owner | Source / declaration | Findings to resolve |
| --- | --- | --- |
| Picker components | app/views/admin_gallery_edit_components.php:117 — view_render_admin_image_reorder_script | return.missing (1) |
| P5 view owner | app/views/admin_gallery_forms.php:271 — view_render_admin_new_gallery_fields | parameter.missing:formModel; return.missing (2) |
| P5 view owner | app/views/admin_uploads.php:84 — view_render_admin_upload_existing_gallery_form | documentation.summary; return.missing (2) |

Cross-file identity/fingerprint matching detected 0 unchanged moves; the
disposable move/copy fixture proves that a removed declaration moved unchanged
retains its legacy status, while copying it alongside its original is new code.
There were zero doc-only regressions and zero coverage blockers. Parent/owner
remediation remains concurrent; the next central JSON is authoritative for the
integrated tree. Counts are observations, never an allowed-debt baseline.

The newly assigned neutral scripts/recovery/cli.php usage declaration now
documents its returned static help string and lack of printing/recovery effects.
Its executable body was not changed. Parent completed the detector controller
contracts and the preflight fixture's explicit opaque-mixed rationale; those
declarations no longer appear in the changed queue. No neutral test edits were
needed, and P1/P2/runtime-owned tests were not rewritten.

Two proven parser false positives were corrected without changing their owners'
source: a property's substantive standard @var description satisfies its meaning
contract, and JSDoc above a static member-assigned callback attaches to that
callback. Bare property types and undocumented callbacks still fail. The new
fixture suite tests both corrections.

Inherited, lexically unused PDO arguments may retain mixed with the documented
per-parameter opaque rationale in CODE_DOCUMENTATION.md; reading, forwarding or
introspecting that argument still fails the shape rule. Constructors and abstract
signatures supply no unused-body proof. Concurrent owner remediation, not just
parser corrections, accounts for the reduction from Quick5's 317 findings.

The later parent Quick7 declaration gate reported three findings: the known
side-panel tuple pair and one parent-owned report parameter contract. Parent
reported the latter corrected. The tuple parser correction is now fixture-tested;
read-only inspection finds **zero documentation findings across all 176 side-panel
declarations**, without runtime edits. The next integrated central gate, not
arithmetic over earlier counts, establishes its current overall status.

## Exact changed-path handoff

These are this worker's edits, not the whole concurrent working-tree diff.

Implementation, declaration-contract and guide paths (17):

- [scripts/check_source_documentation.php](../scripts/check_source_documentation.php)
- [scripts/check_policy_constants.php](../scripts/check_policy_constants.php)
- [scripts/source_contracts/inventory.php](../scripts/source_contracts/inventory.php)
- [scripts/source_contracts/php.php](../scripts/source_contracts/php.php)
- [scripts/source_contracts/javascript.php](../scripts/source_contracts/javascript.php)
- [scripts/source_contracts/changes.php](../scripts/source_contracts/changes.php)
- [scripts/source_contracts/policy_scan.php](../scripts/source_contracts/policy_scan.php)
- [scripts/source_contracts/policy_changes.php](../scripts/source_contracts/policy_changes.php)
- [scripts/check_mvc_boundaries.php](../scripts/check_mvc_boundaries.php)
- [scripts/recovery/cli.php](../scripts/recovery/cli.php) — usage return docblock only
- [tests/function_documentation_test.php](../tests/function_documentation_test.php)
- [tests/source_header_author_test.php](../tests/source_header_author_test.php)
- [tests/source_contract_inventory_test.php](../tests/source_contract_inventory_test.php)
- [tests/source_contract_changes_test.php](../tests/source_contract_changes_test.php)
- [tests/policy_constants_changes_test.php](../tests/policy_constants_changes_test.php)
- [docs/CODE_DOCUMENTATION.md](CODE_DOCUMENTATION.md)
- [docs/SOURCE_CONTRACT_INVENTORY.md](SOURCE_CONTRACT_INVENTORY.md)

Leading-header repair paths (191). Apart from the separately noted usage docblock
in scripts/recovery/cli.php, this worker changed only leading comments in these
paths. Executable behavior changes belong to their other active owners:

<details>
<summary>Expand exact source header paths</summary>

- .github/workflows/gallery-workflows.yml
- app/controllers/admin_gallery_picker_search.php
- app/controllers/admin_gallery_title_completion.php
- app/controllers/smart_galleries.php
- app/models/admin_operation_keys.php
- app/models/gallery_edit_concurrency.php
- app/models/gallery_image_move_journal.php
- app/models/gallery_picker_search.php
- app/policy_constants.php
- app/services/admin_operation_keys.php
- app/services/gallery_creation_safety.php
- app/services/gallery_edit_concurrency.php
- app/services/gallery_image_move_journal.php
- app/services/runtime_support.php
- app/services/smart_galleries.php
- database/migrations/202608140001_smart_galleries.php
- database/migrations/202608140002_smart_gallery_placement.php
- database/migrations/202608140003_smart_gallery_multiple_placements.php
- database/migrations/202608150001_multilingual_content.php
- database/migrations/202608170001_smart_gallery_presentation.php
- database/migrations/202608170002_smart_gallery_attachment_ordering.php
- database/migrations/202609200001_gallery_image_move_journal.php
- database/migrations/202609200002_gallery_edit_revision.php
- database/migrations/202609200003_admin_operation_keys.php
- docs/PHP_Gallery_Manual.tex
- public/assets/gallery-modules/admin-language-selector-design.js
- public/assets/gallery-modules/admin-panel-drafts.js
- public/assets/gallery-modules/admin-panel-lifecycle.js
- public/assets/gallery-modules/admin-panel-policy.js
- public/assets/gallery-modules/admin-settings-search.js
- public/assets/gallery-modules/admin-smart-galleries.js
- public/assets/gallery-modules/gallery-picker-policy.js
- public/assets/gallery-modules/lightbox-preload-lifecycle.js
- public/assets/styles.css
- public/assets/styles/admin-cinematic.css
- public/assets/styles/admin-dashboard.css
- public/assets/styles/admin-duplicate-photo-detector.css
- public/assets/styles/admin-gallery-list.css
- public/assets/styles/admin-gallery-title-completion.css
- public/assets/styles/admin-layout.css
- public/assets/styles/admin-media-tools.css
- public/assets/styles/admin-patch-notes.css
- public/assets/styles/admin-reordering.css
- public/assets/styles/admin-subtabs.css
- public/assets/styles/admin-tags.css
- public/assets/styles/admin-theme-editor.css
- public/assets/styles/admin-theme-preview.css
- public/assets/styles/admin-update.css
- public/assets/styles/admin.css
- public/assets/styles/base.css
- public/assets/styles/lightbox.css
- public/assets/styles/mobile-gallery.css
- public/assets/styles/public-shared.css
- public/assets/styles/public.css
- public/assets/styles/side-panel.css
- public/assets/styles/utilities.css
- public/assets/usage.js
- public/assets/telemetry.js
- scripts/benchmark_title_completion.php
- scripts/benchmark_title_completion_browser.mjs
- scripts/gallery_workflow_ci.php
- scripts/gallery_workflow_mysql.php
- scripts/gallery_workflow_run.php
- scripts/reconcile_image_moves.php
- scripts/recovery.php
- scripts/recovery/cli.php
- scripts/recovery/contracts.php
- scripts/recovery/fixture.php
- scripts/recovery/io.php
- scripts/recovery/validation.php
- tests/admin_auth_mvc_boundary_test.php
- tests/admin_content_localization_test.php
- tests/admin_dashboard_deferred_maintenance_test.php
- tests/admin_gallery_title_completion_browser_test.mjs
- tests/admin_gallery_title_completion_test.mjs
- tests/admin_media_renamer_mvc_boundary_test.php
- tests/admin_mutation_completion_test.mjs
- tests/admin_mutation_stage4_hardening_test.mjs
- tests/admin_operation_keys_test.php
- tests/admin_panel_lifecycle_browser_test.mjs
- tests/admin_settings_navigation_contract_test.php
- tests/admin_settings_rendering_contract_test.php
- tests/admin_side_panel_created_gallery_refresh_test.mjs
- tests/admin_side_panel_delegation_test.mjs
- tests/admin_side_panel_gallery_mutation_test.php
- tests/browser_upload_zip_worker_test.mjs
- tests/content_localization_model_test.php
- tests/deploy_app_packaging_test.php
- tests/fixtures/admin_gallery_title_completion.html
- tests/fixtures/admin_panel_lifecycle.html
- tests/fixtures/early_runtime_probe.php
- tests/fixtures/gallery_picker_parent_integration.js
- tests/fixtures/lightbox_map_navigation.html
- tests/fixtures/runtime_conditional_file.php
- tests/fixtures/runtime_fatal_user_error.php
- tests/fixtures/runtime_json_exception.php
- tests/fixtures/runtime_missing_require.php
- tests/fixtures/runtime_stream_then_exception.php
- tests/fixtures/runtime_uncaught_exception.php
- tests/fixtures/runtime_uncaught_pdo.php
- tests/fixtures/session_contention_router.php
- tests/gallery_benchmark_runtime_scope_test.mjs
- tests/gallery_creation_safety_test.php
- tests/gallery_download_client_test.mjs
- tests/gallery_download_controller_test.php
- tests/gallery_download_manifest_test.php
- tests/gallery_download_service_test.php
- tests/gallery_download_zip64_test.mjs
- tests/gallery_download_zip_test.mjs
- tests/gallery_edit_concurrency_mysql_test.php
- tests/gallery_edit_concurrency_test.php
- tests/gallery_image_move_crash_test.php
- tests/gallery_image_move_files_test.php
- tests/gallery_picker_browser_test.mjs
- tests/gallery_picker_parent_integration_browser_test.mjs
- tests/gallery_picker_search_test.php
- tests/gallery_title_completion_service_test.php

- tests/gallery_trash_model_test.php
- tests/gallery_workflow_browser.mjs
- tests/gallery_workflow_browser_test.php
- tests/gallery_workflow_integration_test.php
- tests/gallery_workflow_safety_test.php
- tests/legacy_download_cache_health_test.php
- tests/lightbox_map_browser_test.mjs
- tests/lightbox_map_navigation_test.mjs
- tests/lightbox_metadata_lifecycle_test.php
- tests/lightbox_preload_lifecycle_test.mjs
- tests/lightbox_resource_lifecycle_test.php
- tests/lightbox_slideshow_preload_test.php
- tests/mutation_response_contract_test.php
- tests/mvc_layer_contract_test.php
- tests/public_card_visibility_eye_test.php
- tests/public_content_localization_test.php
- tests/public_gallery_mvc_boundary_test.php
- tests/public_media_session_release_test.php
- tests/public_media_version_routing_test.php
- tests/public_search_diagnostics_stage0a_test.php
- tests/public_search_mvc_boundaries_test.php
- tests/public_search_progressive_stage2_test.php
- tests/public_search_progressive_stage4_test.php
- tests/public_search_progressive_stage5_test.php
- tests/public_search_progressive_stage7_test.php
- tests/public_search_progressive_test.mjs
- tests/recovery_assurance_test.php
- tests/runtime_normalization_environment_test.php
- tests/runtime_support_surfaces_test.php
- tests/runtime_support_test.php
- tests/seo_lightbox_target_guard_test.php
- tests/session_contention_test.php
- tests/smart_gallery_cycle_placement_test.php
- tests/smart_gallery_high_priority_hardening_test.php
- tests/smart_gallery_medium_hardening_test.php
- tests/smart_gallery_presentation_test.php
- tests/smart_gallery_public_contract_test.php
- tests/smart_gallery_rules_test.php
- tests/stage3_auxiliary_mutation_contract_test.php
- tests/stage3_controller_presentation_boundary_test.php
- tests/stage4_gallery_image_persistence_boundary_test.php
- tests/stage4_mutation_hardening_contract_test.php
- tests/stage5_relational_features_persistence_boundary_test.php
- tests/stage6_identity_auth_boundary_test.php
- tests/support/admin_operation_fixture.php
- tests/support/gallery_edit_runtime.php
- tests/support/gallery_image_move_worker.php
- tests/support/gallery_picker_legacy_view.php
- tests/support/gallery_title_completion_fixture.php
- tests/support/gallery_workflow_browser.js
- tests/support/gallery_workflow_fixture.php
- tests/support/gallery_workflow_http.php
- tests/support/gallery_workflow_router.php
- tests/support/gallery_workflow_safety.php
- tests/support/gallery_workflow_seed.php
- tests/support/lightbox_lifecycle_size.mjs
- tests/support/session_contention.php
- tests/support/session_contention_runtime.php
- tests/tag_page_theme_model_test.php
- tests/thumbnail_format_metadata_consistency_test.php
- tests/upload_automation_checksum_indexing_test.php
- tests/version_094_audit_hardening.php
- winapp/gallery_http_monitor.py
- winapp/gallery_watch_upload.pyw
- winapp/install.bat
- winapp/run_gallery_watcher.bat
- winapp/tests/test_redesign.py
- winapp/uploader/__init__.py
- winapp/uploader/config.py
- winapp/uploader/diagnostics.py
- winapp/uploader/discovery.py
- winapp/uploader/media.py
- winapp/uploader/models.py
- winapp/uploader/state_store.py

</details>
