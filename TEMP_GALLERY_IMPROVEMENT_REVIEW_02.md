# Gallery improvement review - second pass

## Implementation log - active

Implementation authorized on 20 September 2026 from checkout `e7dea53`.
All fifteen priorities were retained. Original findings below remain historical
and are not completion claims. No commit, release/version change, publication,
or mutation of the existing site's data is authorized by this work.

Maintainer decisions:

- Priority 5: replay-safe operation keys approved; fresh keys retain deliberate duplicates.
- Parallel coding agents approved, with parent-agent review and integration.
- Priority 1 uses the recommended preservation-first conflict boundary.
- Priority 7 starts with in-memory drafts and an inline guard; no browser persistence of private forms.
- Priority 9 retains syntax compatibility; no hard PHP minimum increase.

| Item | State | Implementation / verification |
| --- | --- | --- |
| 1 Catalog preservation | Implemented / automated qualification passed | Implicit deletion removed; typed 409 preserves catalog ID. Three-state observation and displaced-folder HTTP tests pass in the full audit. |
| 2 Durable moves | Implemented / automated qualification passed | Journal/transaction marker and exclusive moves pass process-interruption/recovery tests at three stages, with path hardening and bounded health. |
| 3 Edit conflicts | Implemented / full qualification passed | Revision reservation is application-owned: ordinary column migration, explicit model increments and 37 writer boundaries. The trigger-era implementation was removed after hosted-MySQL error 1419; no live migration was applied. |
| 4 Panel load ownership | Implemented / automated qualification passed | Generation and cancellation guards pass the registered real Chromium lifecycle fixture. |
| 5 Retry safety | Implemented / automated qualification passed | Durable actor/operation/payload-scoped keys pass HTTP replay and real browser intent-ownership fixtures; intentional fresh-key duplicates remain supported. |
| 6 Decode budgets | Implemented shared boundary / automated qualification passed | GD admission, overflow/memory policy, optional Imagick refusal/fallback and executed upload pipelines pass. Separate RAW/string decoders and native-memory isolation are not claimed. |
| 7 Unsaved work | Implemented / automated qualification passed | Bounded in-memory title/description drafts and inline guard pass the browser lifecycle fixture; no secret/browser persistence. |
| 8 Dialog focus | Implemented / automated qualification passed | Native keyboard containment and focus restoration pass Chromium; mobile, screen-reader and other engines remain manual acceptance. |
| 9 Runtime support | Implemented / local qualification passed | Maintained-branch guidance, CI policy and shared bounded Admin health are wired; local verification does not prove every hosted matrix job. |
| 10 Bounded picker | Implemented / automated qualification passed | Thirty-result search, selected context, parent/move call sites and no-JS ID directory pass PHP and registered browser fixtures. |
| 11 Session contention | Investigated / fixtures qualified | Synthetic and real authenticated route fixtures pass; late translation diagnostics still write session state. No production early-close change is justified by this evidence. |
| 12 Constants | In progress | Full inventory: 594 findings. Core and browser policy migrations plus a strict changed-runtime gate pass; legacy and unparsed-language remediation remains. |
| 13 Headers | Implemented / automated qualification passed | First-party header checks pass the full audit, with explicit upstream provenance exclusions and no rewritten vendor notices. |
| 14 MVC | In progress | SQL/file/session ownership migrations pass; zero strict violations, with fourteen reviewed architecture rows still requiring follow-through or explicit infrastructure classification. |
| 15 Documentation | In progress | Changed-declaration gate passes with zero findings. Completed model/panel tranche preserves executable tokens; whole inventory still retains 10,262 findings and language-coverage gaps. |

Verification policy: use the central quick audit during integration and the
fixture-enabled central full audit for final handoff. Individual checks are for
new-test development or a diagnosed central failure only. Record failures and
remaining gaps here; never equate an implementation scaffold with completion.

### Work entries

- Initial inspection: clean worktree, all fifteen retained blocks read; saved PHP,
  MySQL and Chromium locations found. Existing live configuration/media/database
  remain outside the test fixture. Original review follows below.
- Catalog preservation: creation now refuses missing/unknown catalog ownership;
  a healthy existing directory retains deliberate suffix creation. Added
  `docs/GALLERY_CATALOG_RECONCILIATION.md` and a disposable subtree/hash assertion.
  New isolated `gallery_creation_safety_test.php` passed during test development.
- Move recovery: durable intent is recorded before file changes; the ownership
  model writes its commit marker inside the same transaction. Recovery never
  treats response delivery as a commit signal. Work is not yet acceptance-complete.
- Integration quick audit 1: 190 PHP passes / 5 failures / 3 expected fixture skips;
  MVC remained zero strict violations. Missing registry/route/translation wiring
  and lifecycle-sensitive source contracts were identified and assigned/fixed.
- Disposable quick audit 2: 194 PHP passes / 7 failures / zero skips. The
  displaced-folder HTTP regression passed, as did the existing full-stack browser
  journey. The new move-worker termination handshake failed on Windows pipes;
  changed to a private fixture result file before claiming crash coverage.
  Remaining failures include in-progress integration/contract updates.
- Parallel browser result: 66 assertions passed using Node 24 and local Edge,
  including native Tab/Shift+Tab/Escape, stale responses, drafts and dynamic saves.
  This does not replace product styling, mobile or assistive-technology review.
- Picker synthetic evidence: 10,000-row fixture markup decreased from 6,035,598
  to 16,454 bytes. This is a fixture HTML/materialization comparison, not measured
  production database latency. See `docs/GALLERY_PICKER.md`.
- Runtime/session evidence: compatibility stays PHP 8.1; deployment guidance uses
  maintained branches. The synthetic session measurement alone does not authorize
  removing session serialization from real authenticated mutations.
- Disposable quick audit 3: 196 PHP passes / 7 failures / zero skips, Node
  17 passes / 1 failure; mutation contracts passed and strict MVC stayed empty.
  Failures exposed mandatory operation-key fixture changes, immediate edit-revision
  acknowledgement for rapid consecutive saves, and unfinished source/translation
  contracts. These are tracked fixes, not waived failures.
- Edit-concurrency development initially used database triggers and passed its
  isolated disposable fixtures. That design was later rejected after a common
  binary-logged MySQL host returned error 1419 for `CREATE TRIGGER`; the superseding
  remediation is recorded below. No live privilege or server-variable change was made.
- MVC integration: session-user projection and setup lookup now use the existing
  auth model/service; mutation context counts use the gallery model/service;
  the legacy unique-slug adapter retains its caller's PDO connection while
  delegating suffix policy and query ownership. No baseline entry was added.
- Disposable quick audit 4: 198 PHP passes / 11 failures / zero skips. The move
  crash test, operation-key HTTP test, edit-concurrency MySQL test, displaced-folder
  workflow and full-stack browser journey all passed. Remaining failures were
  split-module path checks, source contracts affected by ownership/header changes,
  newly added health translations, and a session timing comparison. The overall
  audit is still FAIL; no passing subset is presented as full acceptance.
- Updater pre-activation initially required trigger verification. This historical
  implementation was superseded by the ordinary-column/application-writer design
  below so installation remains self-service on common hosted databases.
- Additional MVC migration: Theme CSS reset/preset/upload and optimized-background
  removal now belong to their existing services. Log export cleanup accepts only
  files allocated by that request. The new isolated preservation test passed.
  Seven log policy definitions moved to the immutable Core owner without changing
  their values or persisted administrator retention preferences.
- Source inventory is now a central audit task with complete JSON artifacts and
  explicit remaining counts (quick audit 4: 10,743 documentation / 695 policy
  findings). PASS on that task means discovery succeeded, not semantic compliance.
- Discovery and Google OAuth now pass only their caller-owned job/state maps to
  services; controllers retain the session boundary. New isolated tests preserve
  discovery expiry/retention and OAuth caller isolation, one-time consumption,
  expiry and link identity. No provider request or live login was performed.
  Five discovery and five OAuth endpoint/entropy/lifetime policies now have
  documented immutable Core definitions.
- Newly added or materially changed declarations now have a separate failing
  central documentation gate against Git HEAD. Missing history or unsupported
  changed-language coverage reports BLOCKED, never success. Its first integrated
  source snapshot found 530 issues; reviewed remediation is in progress, with
  legacy debt retained separately rather than hidden behind a baseline.
- Frontend policy extraction now covers title completion, Settings search and
  navigation-data interactions; migration scheduling policy is integrating.
  These are bounded slices of Priority 12, not a magic-value-free completion claim.
- Disposable quick audit 5: 220 PHP passes / 4 failures / zero skips, Node 19
  passes / 1 failure. Move-crash, replay HTTP, edit concurrency and the full-stack
  panel journey passed again. Remaining failures: new actual-route session worker
  readiness, cache-revision contracts and five new browser message translations.
  Changed-declaration findings decreased to 317; complete inventory still records
  10,915 documentation and 634 policy findings. Strict MVC remained empty.
- Complete-report job lifecycle now accepts an explicit caller-owned checkpoint;
  its controller publishes or clears session state in a finally boundary. The
  isolated missing-job/read/write/clear/isolation test passed. Seven existing
  report policy constants moved to Core with unchanged values and explicit imports.
  Report query/browser literal policy is still part of the remaining Priority 12 queue.
- Duplicate-detector start/process/read/prune/write/cleanup now accepts an explicit
  caller-owned map. Its controller supplies only the detector compartment, including
  dynamically rendered deletion and ledger actions. The new isolated regression
  passed empty-scope completion, caller isolation, expiry, retention and the actual
  by-reference controller adapter. Ten detector policies now have documented Core
  definitions. Existing pre-start retention semantics are preserved explicitly:
  the three newest previous jobs plus the just-created job can coexist.
- Disposable quick audit 6: 227 PHP passes / 1 failure and Node 19 passes /
  1 failure; no skips or blockers. The real authenticated-route session fixture
  now passes. The changed-declaration documentation gate passes with zero findings
  and zero coverage blockers; whole-tree debt remains separate (10,485 doc /
  618 policy findings). Two source-text readers need to tolerate meaningful added
  docblocks without weakening their bounded-upload and panel-lifecycle assertions.
- Setup-lock observation/writing now delegates from the historical Core facade to
  the existing auth/setup service. The copied-service fixture passed normal marker
  writes and a bounded storage obstruction; the live installation lock was untouched.
- Migration transfer allocation/cleanup now belongs to its module, including all
  four former controller unlink paths. Outgoing ZIPs use an exclusively reserved
  temporary name instead of unlinking it to append a suffix. The isolated ownership
  and ZIP-content fixture passed; unrelated temporary files cannot be released.
- Continued retained P12/P15 work after the feature checkpoint: legacy gallery-model
  and side-panel documentation, report-browser policy, a no-new operational-policy
  gate, and explicit anti-automation ticket context are being handled in parallel.
- Superseded trigger-era review found that edit-trigger verification normalized quoted
  SQL values as well as keywords. Replaced it with quote-preserving token comparison:
  changed lock-suffix case/spacing, changed quoted error identity and incomplete
  syntax now refuse. The new isolated token test passed; the deployment-preflight
  matrix was extended and awaits the next central pass.
- Decoder pipeline integration: reviewed the new executed classic-upload and
  client-prepared/server-completion fixture and registered GD as a required
  dependency (missing is BLOCKED). This fixture uses tiny real GD images with
  isolated SQL/scanner/transport seams; it is not a live-server or OOM experiment.
- Report policy: model and service now share the same batch ceiling; browser
  requests omit batch_size so the controller's central default is authoritative.
  Existing telemetry windows and per-section SQL/output limits moved to documented
  Core definitions with unchanged values. The new isolated policy test passed
  integer bindings, minimum/maximum bounds, output limits and stable group ranking.

- Disposable quick audit 7 (superseded trigger design): 232 PHP passes / 1 source-contract failure, Node
  20 passes / 1 source-contract failure; zero skips or blockers. Exact trigger
  verification passed real MySQL and preflight cases. Decoder upload integration,
  report policy, migration temp ownership and Viewer Phase43 contracts passed.
  Strict MVC remained clean, with advisory rows down to 14. Documentation gate
  identified two tuple-parser findings and one imprecise test parameter annotation.
  Whole inventory: 10,265 documentation / 602 policy findings, not completion.
- Legacy documentation: both gallery model files now have reviewed parameter and
  result contracts (62 functions and three callbacks), with unchanged executable
  tokens/SQL. Side-panel documentation covers 176 declarations with unchanged
  executable tokens; the remaining tuple-pattern scanner case is being resolved.
- Viewer anti-automation now receives explicit private context. Actual-controller
  isolated tests cover one-use tickets, independent contexts, expiry/retention,
  disabled/no-op state, failed-proof replacement and finally publication after
  signing/business failures. No production session closing or auth policy changed.
- WebDAV body allocation/copy/cleanup moved into its existing service. The
  controller retains authentication, schema preflight, request stream opening/
  closing and HTTP mapping. Late schema refusal preserves a still-staged body;
  OS temporary-file retention is not a durable recovery workflow. New isolated
  stream/fault/ownership fixture passed development and awaits integration.

- Disposable quick audit 8: all 234 PHP and 21 fast Node tests passed, with zero
  skips/blockers and clean syntax/MVC/mutation contracts. Changed-declaration
  documentation is now clean, including flat destructured tuple callbacks.
  The new runtime-policy gate correctly failed on 36 missing explanation fields
  across ten Core constants. Those fields now document actual units, owners and
  compatibility rationale without changing values. Whole-tree debt remains
  10,262 documentation / 602 policy findings; full qualification follows.

### Remaining implementation and acceptance queue

Final integration checkpoint: `cache/test-audit/20260920-070938-14964`,
profile `full`, PASS in 222.42 seconds. Results: 234 PHP tests, 22 Node tests,
36 WinApp tests and five Chromium integration checks; zero failures, skips or
blockers. Complete syntax checks cover 857 PHP and 105 JavaScript files.
Both changed-source gates, mutation contracts and strict MVC pass. Inventory
success is not a legacy-compliance claim.

The 708-file manifest was regenerated and checked for unchanged version 0.104.1.
Git whitespace checks pass. Nothing was staged or committed; no live migrations,
configuration, gallery data or installed runtime were changed by test setup.
Disposable database/server/browser fixtures were cleaned up successfully.

- Post-handoff migration remediation (2026-09-20): removed every trigger and
  server-policy requirement from migration `202609200002`. The migration now adds
  only `galleries.edit_revision`; every supported application model UPDATE advances
  that revision explicitly, while multi-step/filesystem workflows retain the shared
  writer lock. The Admin runner's existing duplicate-column replay lets an installation
  that already hit MySQL error 1419 retry from the Gallery page and complete its ledger
  entry without DBA action, grants, global-variable changes or manual SQL. User-facing
  failure output is bounded and no longer exposes the raw PDO/MySQL exception.
  Disposable quick qualification passed 234 PHP, 21 fast Node, 36 WinApp, strict
  MVC, changed-declaration documentation and changed-policy checks with no failures,
  skips or blockers. The actual MySQL fixture reproduced a present revision column
  with its ledger entry removed, then proved the full runner records the migration
  exactly once. Final full qualification passed 234 PHP, 22 Node, 36 WinApp and
  five Chromium integration checks, complete PHP/JavaScript syntax, strict MVC,
  changed-declaration documentation and changed-policy checks with no failures,
  skips or blockers. The 708-file managed manifest was regenerated and verified.

- Priority 12: continue semantic migration of legacy policy definitions/literals.
  The new gate covers recognized changed runtime PHP/JS sites, not all CSS,
  embedded code, native CLI/Windows languages, configuration-map entries or
  duplicated cross-module meanings. Preserve configurable versus immutable owners.
- Priority 14: resolve the remaining fourteen reviewed architecture rows where
  migration is needed, especially translation/navigation-data session ownership
  and gallery-access context. Infrastructure adapters need an explicit ownership
  decision, not a disguised service/global wrapper or an added baseline exemption.
- Priority 15: continue whole-file documentation review beyond the completed
  gallery-model and side-panel tranche. Remaining counts include signatures,
  callbacks, meaningful shapes and unparsed language forms; strict changed-code
  success does not certify all legacy declarations.
- Priority 11: retain measured actual-route contention evidence. No production
  early-close change was justified because late diagnostic/session writes remain;
  a future optimization must first relocate that state and preserve auth/CSRF.
- Browser/manual acceptance: automated Chromium fixtures do not replace mobile,
  screen-reader, Firefox/WebKit and deployment-specific UI acceptance. Record
  unavailable/manual checks honestly and never test them destructively on live data.
- Hosted PHP matrix: CI configuration and local PHP 8.3 fixture success do not
  establish that every maintained-version hosted job has actually run.

## Original review and retained requirements

<!-- The original review below is preserved; current evidence lives in the implementation log above. -->

Prepared 20 September 2026 against application **0.104.1**, checkout **65d287e**. The working tree was clean when this review began.

This is a fresh source-based review, not a declaration that every risk below has occurred on your installation. I traced the relevant controllers, services, models, browser handlers, and existing test/operational documentation. No real galleries, credentials, configuration, database rows, or application code were changed. I did not run destructive reproductions or a new regression audit for this Markdown-only task.

The previous release audit is useful historical evidence: twelve suites passed, with 191 PHP, 18 Node, 36 WinApp, and two standalone Chromium checks, without skips. That does not establish coverage of the new scenarios below or substitute for testing a future implementation.

**How to select:** keep the complete `## Priority ...` sections you want considered, and delete the others. Every priority contains its own scope, implementation outline, and acceptance criteria. There is deliberately no duplicate task list elsewhere to reconcile. Keeping a section selects a proposal; it does not authorize production data changes, publication, or a runtime-support break.

**How to read confidence:** “confirmed mechanism” means the behavior is visible in the inspected code; “risk to reproduce” means the resulting failure still needs a controlled test. Effort estimates are rough engineering days, including focused regression work, not delivery promises.

Already delivered work is not proposed again: bounded title suggestions, Unicode suffix repair, the nearby-preview lifecycle extraction, disposable workflow infrastructure, the recovery-evidence CLI, and artifact-bound release qualification remain in place. The original history is preserved in [docs/GALLERY_IMPROVEMENT_HISTORY.md](docs/GALLERY_IMPROVEMENT_HISTORY.md). Real off-host recovery and human/device sign-off still require operational evidence; they should not be replaced by another tooling rewrite.

Implementation of any selected item must retain strict Controller -> Service -> Model ownership, canonical capability/schema policy, the shared Admin mutation envelope/completion coordinator, both thumbnail renderers, and existing authorization. Register new regression coverage with `scripts/audit.php`; use its prescribed profile rather than manually replaying the test tree. Refresh the core manifest before a code handoff.

## Priority 1 - Stop ordinary gallery creation from silently deleting an older catalog subtree

**Type:** data safety; new finding. **Confidence:** confirmed destructive control flow; exceptional filesystem failures not reproduced. **Effort:** 2-4 days for the first safe boundary and tests.

### Why this is first

A missing folder should not make an ordinary “create gallery” request double as an irreversible metadata-cleanup request. Owners can temporarily rename or move folders through FTP, restore files later, or encounter a storage problem. Losing the database's descriptions, image associations, tags, and other dependent rows makes recovery harder even when original photographs still exist elsewhere.

### Evidence

- `app/services/gallery_sidecars.php`, `create_empty_gallery()`, around lines 675-678: if the requested target is not a directory, creation calls `delete_missing_gallery_database_subtree_by_folder_path()` before choosing the final target and calling `mkdir()`.
- `app/services/gallery_mutations.php`, `delete_missing_gallery_database_subtree_by_folder_path()`, around line 262: an `is_dir()` result gates selection and deletion of the matching database subtree.
- `gallery_delete_database_subtree_rows()` correctly preflights database schema, and `app/models/gallery_mutations.php` deletes dependent rows transactionally. Those protections do not establish that the filesystem absence is intentional or that deleting the catalog is appropriate for a create request.
- `app/services/gallery_paths.php` validates root and parent boundaries. This is **not** a claim that arbitrary paths or a completely missing root bypass those checks. The gap is the meaning assigned to an absent target inside an otherwise valid parent.

### Proposed implementation

1. Add a disposable regression: create a gallery with metadata and a child, move its folder aside inside the fixture, then request a new gallery at the old path. Assert the original catalog remains recoverable.
2. Separate ordinary creation from stale-catalog cleanup. Prefer a bounded conflict response identifying the existing catalog object; offer discovery/reconciliation as a distinct, explicitly chosen workflow.
3. Treat filesystem observation failures as unknown, not confirmed absence. Use bounded root/parent identity and accessibility checks, and refuse ambiguous mutations. Do not attempt to make one `is_dir()` call prove storage health.
4. Reuse the existing discovery, Trash, and mutation-policy owners. If confirmed stale rows may be removed, preserve a recoverable metadata snapshot and show the scope before deletion; do not introduce a second Trash implementation.
5. Keep any persistent panel resolution in place through the canonical JSON envelope. A normal create action should not unexpectedly delete data or navigate away.

### Success criteria

- A temporarily displaced folder, an observation failure, or a failed subsequent `mkdir()` cannot cause ordinary creation to erase the old subtree.
- Genuine stale records have an explicit, documented reconciliation path.
- Available/missing/unknown schema behavior and path confinement remain enforced.
- Tests verify original IDs, dependent rows, and recoverability, not only HTTP status.

**Decision needed:** choose whether a conflicting missing-folder catalog should block creation or require explicit reconciliation. Recommended: block by default and preserve the existing catalog.

## Priority 2 - Make physical file moves recoverable after process termination

**Type:** data safety; new finding. **Confidence:** confirmed in-memory recovery boundary; crash outcome needs fault injection. **Effort:** 4-7 days for image moves first.

### Why it matters

Compensating inside a catch block handles ordinary exceptions, but not a killed PHP worker or machine shutdown. Database transactions cannot automatically reverse a preceding filesystem rename. A partially completed move can leave database ownership pointing to the source while some originals are already at the destination.

### Evidence

- `app/services/gallery_mutations.php`, `move_gallery_images()`, around lines 848-972: the move manifest and completed rename list are local arrays; files move first and the model commits ownership afterward.
- `gallery_rollback_image_file_moves()`, around line 1109, attempts reverse renames with suppressed errors and no structured rollback-failure result.
- `app/models/gallery_mutations.php`, `gallery_mutation_model_move_images()` provides an atomic database transaction. This is useful existing protection, not durable filesystem recovery.
- The existing `app/services/gallery_trash.php` and updater job modules already demonstrate durable states and reconciliation. Reuse their design principles without routing unrelated moves through their storage.

### Proposed implementation

1. Start with image moves, not a rewrite of every mutation. Define durable states such as prepared, moving, database-committed, finalized, and needs-reconciliation.
2. Record a bounded operation manifest and stable object identities before the first rename, after full schema/path preflight. Models own ledger persistence; the domain service owns filesystem orchestration.
3. Serialize conflicting source/destination operations in a stable lock order. Revalidate destination ownership and collision assumptions under that protection.
4. On resume, inspect actual files and committed database ownership to decide whether to finish or compensate. Never overwrite an unrelated destination that appeared after the original preflight.
5. Report incomplete compensation explicitly. Preserve the evidence and remaining files for repair instead of reporting an unqualified rollback.
6. Integrate bounded reconciliation with existing maintenance/health discovery; do not scan the entire media tree on every public request.

### Success criteria

- Kill only the owned disposable worker after the first rename, before database commit, and after commit; a later reconciliation reaches a consistent result.
- Permission-denied rollback and concurrent destination collisions are visible and recoverable.
- Original byte hashes, IDs, covers, sidecars, and derivative ownership agree after success or recovery.
- No bulk destructive cleanup runs merely because an operation timed out.

**Scope boundary:** gallery-folder moves can follow once the image-move lifecycle is proven. This is separate from off-host backup and does not replace it.

## Priority 3 - Detect stale edits before one administrator overwrites another

**Type:** editorial correctness and access-setting safety; new finding. **Confidence:** confirmed missing revision condition in the inspected generic save path. **Effort:** 3-5 days for gallery metadata first.

### Why it matters

Two tabs can load the same gallery, make different changes, and save minutes apart. The later form can silently restore older titles, descriptions, or visibility choices. Browser response-generation guards protect displayed refreshes; they do not prevent stale writes reaching the database.

### Evidence

- `app/models/galleries.php`, `gallery_model_update_fields()`, around lines 186-242: updates use `WHERE id = ?`, without an expected edit revision.
- `app/services/gallery_editor_mutations.php` forwards the field map and current timestamp; it does not perform compare-and-swap.
- `app/controllers/admin_galleries_edit_actions.php`, `admin_save_gallery_from_input()`, can move the folder around line 889 before updating generic fields around line 1142.
- Existing lightbox/public-context `revision` values are refresh/postcondition metadata, not an editor's submitted persistence precondition.

### Proposed implementation

1. Introduce a dedicated monotonically changing edit revision through a new migration, or another proven compare-and-swap token. Second-resolution `updated_at` alone is insufficient for rapid competing edits.
2. Include the expected revision in prepared form data and enforce it atomically in the model. Recheck it before folder/asset side effects, with an operation boundary that avoids a successful precheck followed by a racing write.
3. Return a typed conflict response, keep the panel and entered values intact, and show the latest stored values for comparison.
4. Prefer reload-and-review or explicit field-level merge for the first version. Never silently auto-merge privacy/password/share settings.
5. Ensure all writers of the protected fields participate in revision advancement, including bulk or specialized actions; protect one clearly defined entity boundary before expanding.

### Success criteria

- Two independent sessions read the same revision; only one conflicting save succeeds.
- The rejected request changes no folder, asset, protected setting, or sidecar.
- Unrelated later edits can be reviewed and retried without losing the user's draft.
- Direct-page/no-JavaScript forms receive a usable conflict recovery path too.

**Reference:** HTTP conditional writes address this same lost-update problem; `If-Match` is one transport option, not a requirement to turn the existing PHP forms into a REST API. [MDN: If-Match](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/If-Match).

## Priority 4 - Give initial side-panel loads an explicit owner

**Type:** wrong-context UI race; new finding. **Confidence:** confirmed missing ownership check in initial load; interaction needs a browser regression. **Effort:** 1-2 days.

### Why it matters

Click gallery A, then B while A's editor is still loading. If B returns first and A returns last, an old response can populate the shared panel after the heading/workflow has already switched. This can present the wrong editor or steal focus after the panel was closed.

### Evidence

- `public/assets/gallery-modules/admin-side-panel.js`, `openAdminGallerySidePanel()`, around lines 585-627: writes the heading, loading body, fetched HTML, and source URL without a request-generation check or abort signal.
- The same function focuses the first field after an awaited response without checking that this open intent is still current.
- Later mutation-owned refresh code, around lines 1940-1970, already accepts a completion guard. The fix should complement that owner, not duplicate its post-mutation retry logic.
- Existing `tests/support/gallery_workflow_browser.js` covers a delayed editor refresh overtaken by another save, not the full initial A -> B -> late-A lifecycle.

### Proposed implementation

1. Give the drawer mount/open operation a generation and cancellation scope. A new open or close invalidates the previous load.
2. Before changing body, heading, source URL, status, or focus, verify the request still owns the current open operation.
3. Keep fetched HTML detached until accepted. Treat aborts as ordinary cancellation, not user-visible load failures.
4. Keep mutation completion refresh/retry inside `admin-mutation-completion.js`. Define how panel-open ownership composes with a mutation already in progress.
5. Update the deployed import/cache revision and add actual delayed-response browser fixtures.

### Success criteria

- A -> B with reversed response order shows only B, including its heading, source URL, and form target.
- Open -> close -> late response does not reopen the drawer or focus a hidden field.
- Open A -> close -> open C cannot be overwritten by A.
- Dynamically replaced controls still work; URL and panel persistence contracts remain intact.

## Priority 5 - Make create and classic-upload retries distinguishable from intentional duplicates

**Type:** reliable mutations; explicit follow-up from the first review, not newly discovered. **Confidence:** confirmed existing semantics. **Effort:** 3-5 days for creation, with classic upload as a separate extension.

### Why it matters

A server can commit a request while its response is lost. Retrying that request should not accidentally create a second gallery or upload, but two deliberately separate actions must still be allowed to create similar content.

### Evidence

- `app/services/gallery_sidecars.php` uses `unique_gallery_child_folder_path()` when creating a gallery.
- `app/services/uploads.php`, `unique_gallery_upload_target()`, selects another filename when the file or row already exists.
- `docs/GALLERY_WORKFLOWS.md` and the archived first review explicitly distinguish browser double-click suppression from server-side replay protection.
- `app/services/browser_uploads/batch_state.php` already owns prepared-batch state and response caching. Its established behavior should be preserved, not replaced with a second competing prepared-upload pipeline.

### Proposed implementation

1. Approve the semantics first: a fresh operation key means a new intentional request; replaying the same key means recover or return the original outcome.
2. Scope the key to authenticated actor, operation, target, and a canonical payload fingerprint. Reject reuse with changed data.
3. Persist pending/completed/failed-or-recoverable state durably, using atomic claim/uniqueness rather than an in-memory flag or only the PHP session.
4. Reauthorize every retry and revalidate referenced objects before replaying a response. An operation key is not an access token.
5. Reuse the original stable entity IDs and canonical mutation envelope. Do not infer completion by matching a title or a filename.
6. Define expiry, cleanup, interrupted-operation reconciliation, and the relation to physical move/create journals before adding upload coverage.

### Success criteria

- Concurrent same-key requests and retries after a lost response produce one result and stable IDs.
- Same key with different payload returns a clear conflict.
- New keys preserve deliberate repeated creation/upload behavior.
- Database unavailability or unknown required schema refuses before target mutation; interrupted work remains diagnosable.

**Decision needed:** adopt replay-safe request semantics or deliberately retain suffixed-copy behavior. No new storage or policy should be introduced without that choice.

## Priority 6 - Bound image decoding before allocating large native image surfaces

**Type:** availability and ingestion resilience; new finding. **Confidence:** confirmed absence of an explicit budget in the inspected GD path; no resource-exhaustion experiment performed. **Effort:** 2-4 days for the shared decoder boundary.

### Why it matters

A compressed file's upload size does not describe the memory required to decode it. Large panoramas or highly compressed images can consume substantial memory even when the desired thumbnail is small. Native decoder failure may terminate the worker before ordinary PHP recovery runs.

### Evidence

- `app/services/thumbnail_generation.php`, `create_image_thumbnails_result()`, obtains dimensions and later calls `image_create_from_path()` around line 424.
- `image_create_from_path()`, around line 775, dispatches directly to GD decoders.
- `app/services/thumbnail_formats.php`, `thumbnail_generation_policy_summary()`, describes formats, sizes, and quality; it is not an input-pixel or decoder-memory admission policy.
- Runtime diagnostics expose `memory_limit`, and download-cache code has disk-space handling. Neither establishes a shared predecode budget for this image path. This is not a claim that every format or upload route lacks all existing limits.

### Proposed implementation

1. Inventory callers of the shared decoder and existing RAW/Imagick/browser preparation limits. Extend the established image/thumbnail owner rather than adding controller-specific checks.
2. Validate dimensions and overflow-safe pixel counts before decoding. Account conservatively for source, rotated/intermediate, and target buffers; document that estimated memory is not an exact native-allocation guarantee.
3. Define behavior for unlimited/unknown PHP memory limits and unavailable image metadata. Where necessary, isolate heavier conversion in an owned worker with explicit timeout/resource controls.
4. Refuse or defer expensive derivative generation without deleting an already accepted original or weakening media authorization.
5. Return a bounded, translated explanation distinguishing unsupported format, unsafe resource demand, and transient processing failure.

### Success criteria

- Synthetic extreme dimensions are rejected before calling a real high-memory decoder.
- Representative large legitimate photos still work within the documented policy.
- Tests cover budget arithmetic, rotation buffers, missing metadata, low-memory configuration, and processing failure.
- Public repair, classic upload, and prepared-upload fallback do not silently bypass the shared admission policy.

**Scope boundary:** do not arbitrarily reduce upload-byte limits or claim protection against every native-library vulnerability.

## Priority 7 - Protect unsaved text when the Admin panel changes context

**Type:** everyday editorial usability; new finding. **Confidence:** confirmed body replacement without a dirty-form boundary in the inspected panel owner. **Effort:** 2-4 days for gallery title/description fields.

### Why it matters

Long descriptions, translations, and carefully edited titles are valuable work. Switching to another panel workflow should not silently throw them away. This is distinct from stale saves: the text may never have reached the server.

### Evidence

- `public/assets/gallery-modules/admin-side-panel.js`, `openAdminGallerySidePanel()` replaces the current body with loading markup before fetching the next form.
- `closeAdminGallerySidePanel()` hides the drawer, but a later open replaces its body. Closing does not itself prove the draft was saved.
- The inspected Admin modules contain no shared dirty-form, `beforeunload`, or recoverable editor-draft lifecycle. Existing metadata-organizer and AI “draft” concepts describe different feature workflows.

### Proposed implementation

1. Begin with the ordinary gallery title/description form and explicitly identify which values represent user edits versus server refresh state.
2. Keep a small in-memory draft per entity/workflow, and provide inline “unsaved changes / keep editing / discard” behavior before a context replacement. Do not introduce surprise browser confirmation dialogs for every action.
3. Clear a draft only after confirmed success for the same entity and submitted revision; an unrelated successful mutation must not erase it.
4. Preserve draft text after validation or authentication failure and offer deliberate restoration after reauthentication. Never automatically resubmit a write after login.
5. If surviving reloads is later requested, specify browser-storage privacy, expiry, size limits, and account scoping first. Do not persist passwords, tokens, uploads, or private form snapshots indiscriminately.
6. Preserve submitted in-flight operations as their own lifecycle. Closing a panel does not undo a server mutation.

### Success criteria

- Typing a long description, opening another gallery, closing/reopening, and receiving a delayed refresh never silently loses the only copy of the edit.
- A deliberate discard is possible and does not submit anything.
- Failed saves retain the text; successful saves clear only the matching draft.
- If edit revisions are implemented, restoring a draft detects a newer server revision instead of bypassing conflict review.

**Decision needed:** in-memory recovery only, or persistent local drafts with an explicit privacy/retention policy? Recommended first scope: in-memory recovery and an inline context-change guard.

## Priority 8 - Make the side panel's keyboard behavior agree with its declared dialog semantics

**Type:** accessibility and interaction correctness; new finding. **Confidence:** confirmed modal declaration and missing explicit focus lifecycle in this module; assistive-technology impact requires actual review. **Effort:** 2-3 days plus device/screen-reader review.

### Why it matters

The title field now has better accessible help, but it lives inside a larger interaction surface. If the drawer is announced as modal while keyboard focus can move into the background, or focus disappears when it closes, keyboard and screen-reader users receive conflicting behavior.

### Evidence

- `public/assets/gallery-modules/admin-side-panel.js`, `ensureAdminGallerySidePanel()`, around line 994, renders `role="dialog"` with `aria-modal="true"`.
- Opening focuses the first field after loading; closing toggles classes, `aria-hidden`, and `hidden`.
- The inspected panel setup has no explicit opener-focus restoration, background `inert` management, or Tab-cycle containment. This is a source finding, not a completed screen-reader conformance assessment.

### Proposed implementation

1. Decide whether the panel is genuinely modal. If background interaction is intentional, use honest non-modal semantics and a documented way to move between regions. If modal, contain keyboard focus and prevent background interaction.
2. Give focus behavior one owner shared by create/edit/upload panel workflows. Save the opener, choose initial focus deliberately, and restore focus to a still-connected logical target on close.
3. Recompute eligible controls after dynamic fragment replacement and handle an empty/loading/error panel safely.
4. Preserve nested-widget priority: title-completion dismissal, searchable pickers, and other local popovers receive their intended keys before the drawer closes.
5. Combine real keyboard browser fixtures with human screen-reader review; retain the non-JavaScript direct-page fallback.

### Success criteria

- Tab and Shift+Tab follow the chosen modal/non-modal contract.
- Close, Escape, load failure, and fragment replacement leave focus on a visible meaningful control.
- Background content is not simultaneously treated as interactive and announced as unavailable.
- No URL change or page reload is introduced into panel-owned mutations.

**Reference:** the W3C dialog pattern defines modal focus containment and return behavior; its semantics should match the actual interaction, rather than adding an ARIA attribute alone. [W3C: Dialog (Modal) Pattern](https://www.w3.org/WAI/ARIA/apg/patterns/dialog-modal/).

## Priority 9 - Separate “runs on PHP” from “supported for deployment,” and test that promise

**Type:** runtime support policy and release confidence; new review finding. **Confidence:** confirmed documentation/CI mismatch, with upstream lifecycle verified on the review date. **Effort:** 1-3 days for policy and a practical CI matrix.

### Why it matters

Syntax compatibility is not the same as receiving upstream security fixes. The supported-platform statement should help owners select a maintained runtime, and CI should prove the versions/extensions the product actually promises.

### Evidence

- `README.md` describes PHP 8.1+ and lists PHP 8.1 or newer as a requirement.
- `.github/workflows/gallery-workflows.yml` tests two database products, but fixes PHP to `8.3`. Its explicit extension list does not include `intl`.
- Title completion intentionally changes normalization mode when `intl` or `mbstring` is unavailable; that is a real supported behavior deserving explicit environment coverage.
- PHP lists 8.1 as end-of-life since 31 December 2025. At review time, 8.2 security support ends on 31 December 2026; 8.3, 8.4, and 8.5 have later support dates. [PHP unsupported branches](https://www.php.net/eol.php), [PHP supported versions](https://www.php.net/supported-versions.php).

The known local audit used PHP 8.3.30. This is **not** a finding that your production host runs PHP 8.1 or that the code is already incompatible with newer PHP.

### Proposed implementation

1. Publish separate minimum syntax compatibility and recommended maintained deployment versions. Make an explicit maintainer decision before raising the hard runtime minimum.
2. Reuse existing diagnostics/System Health presentation for a bounded runtime-support warning; do not add another feature flag or a network lookup to every request.
3. Build a small CI matrix: the declared maintained minimum and a current newer branch, retaining MySQL and MariaDB coverage without multiplying every combination unnecessarily.
4. Explicitly exercise Unicode-enabled and ASCII-fallback environments, and promote relevant warnings/deprecations to actionable test failures.
5. Keep central audit orchestration. Record which matrix cases actually ran; a configured GitHub workflow is not evidence of a successful hosted run.

### Success criteria

- README, setup guidance, diagnostics, and CI communicate the same support policy.
- Maintained target branches run registered tests without unexpected warnings or skipped mandatory workflows.
- Both title-normalization modes are exercised deliberately.
- Existing installations are not locked out or silently upgraded by a documentation-only change.

**Priority adjustment:** if a real deployment is still on an end-of-life branch, the hosting upgrade assessment moves ahead of the convenience items in this review.

## Priority 10 - Bound the separate parent/move-destination picker, not just title suggestions

**Type:** large-collection performance; new surface, related to the first review's measured problem. **Confidence:** confirmed whole-catalog materialization; user-visible latency not measured. **Effort:** 2-4 days after a short baseline.

### Why it matters

The title-completion endpoint is bounded now, but the separate searchable gallery picker still scales with the entire catalog. Large create/move forms can retain substantial HTML and DOM work despite the title improvement.

### Evidence

- `app/models/galleries.php`, `gallery_model_picker_rows()`, around line 250, selects every gallery and calls `fetchAll()` without a limit.
- `app/services/gallery_picker.php`, `gallery_search_picker_rows()`, builds title/path/label/search fields for the complete result.
- `app/controllers/admin_gallery_renderers.php` passes these rows to `app/views/admin_gallery_renderers.php`, which renders one option button per row.
- `public/assets/gallery-modules/searchable-gallery-picker.js`, `applyFilter()`, scores and sorts all options, then keeps twelve and updates visibility across the original set.
- This is not the new eight-candidate title-completion control; the two interfaces have different semantics and must not share an inappropriate endpoint merely because both involve titles.

### Proposed implementation

1. Measure HTML bytes, DOM node count, memory, and input filtering for synthetic 100/1,000/10,000-gallery forms, including duplicate names and deep paths.
2. Return the committed selection/ancestry plus a bounded authenticated search page, or lazy-load branches where hierarchy browsing is more useful. Preserve a real no-JavaScript selection route rather than embedding the whole tree as a hidden fallback.
3. Keep canonical destination validation in the domain service: self/descendant moves and invalid Smart Gallery relationships remain rejected even if a client supplies an arbitrary ID.
4. Preserve selected hidden-ID semantics, explicit commitment, keyboard navigation, root selection, and path disambiguation.
5. Add debouncing, cancellation, and current-query ownership. Share primitives where useful, but retain the existing picker as the domain owner.

### Success criteria

- A 10,000-gallery form does not materialize 10,000 option nodes.
- Define and meet explicit returned-row/HTML budgets, for example a proposed first target of at most 30 search results plus bounded selection context.
- A selected gallery remains understandable even when absent from the current search page.
- No performance claim is made until the same fixture shows before/after improvements; production query plans are measured separately.

**Scope boundary:** preserve complete server validation and a useful no-JavaScript workflow. Do not solve the problem by removing destination functionality for large collections.

## Priority 11 - Measure and reduce Admin session-lock contention

**Type:** targeted performance investigation; new candidate, not a proven latency bug. **Confidence:** confirmed route-policy distinction; actual contention depends on session handler and concurrent workload. **Effort:** 1-2 days to measure, then a separate estimate for a justified change.

### Why it matters

An expensive request holding a file-backed PHP session can make another request from the same administrator appear slow before its useful work begins. A small title query can feel unresponsive if it waits behind a longer upload or editor operation.

### Evidence

- `app/bootstrap/session.php` already releases the session early for a deliberate read-only media allowlist.
- `app/bootstrap/routing.php`, `cms_route_is_read_only_media_asset()`, lists media/thumbnail/download assets, not `admin_gallery_title_completion`.
- The title-completion controller resolves authentication and performs its read without an explicit close in that controller. `app/controllers/admin_uploads.php` also combines authentication/session behavior with potentially lengthy processing.
- Existing benchmark/test-run analysis already records session-start timing and warns that it includes storage I/O and decode time, not pure lock wait. Reuse that instrumentation.

### Proposed implementation

1. In the disposable environment, run one deliberately bounded slow request and a concurrent authenticated suggestion request with the same cookie, then compare against a separate session.
2. Record queueing/total latency, session handler, and session-start measurements. If there is no meaningful contention, stop after documenting the result.
3. For proven read-only candidates, finish remember-login restoration and required session writes before releasing the lock. Audit the complete route lifecycle for later flash/CSRF/preference/session writes.
4. Do not put Admin reads into the media allowlist merely to reuse its name, and do not globally close all sessions.
5. Before releasing a mutating upload session early, prove that operation/entity locks protect cross-request concurrency; a session lock must not be removed where code accidentally relies on it for serialization.

### Success criteria

- The same-session/independent-session comparison makes the bottleneck and improvement reproducible.
- Login restoration, CSRF checks, logout behavior, session-backed preferences, and error reporting remain correct.
- Different requests can overlap only where domain-level mutation safety permits it.
- A no-change conclusion is acceptable if measurement does not justify implementation.

**Decision needed:** authorize a short investigation first, not an unconditional concurrency refactor.

## Priority 12 - Eliminate magic values and centralize documented constants

**Type:** explicit maintainer requirement; repository-wide consistency. **Status:** requested review/implementation scope, not yet audited or implemented.

### Required outcome

Audit the entire first-party codebase for hardcoded policy values and scattered constant definitions. Reuse the existing centralized definition mechanism, move existing applicable constants there, and replace unexplained literal values with meaningfully named constants. Every constant must explain its purpose, units, scope, and why that value was chosen.

### Implementation steps

1. Inventory PHP, JavaScript, CSS, CLI scripts, and Windows tooling. Include timeouts, delays, retry counts, batch/result limits, byte/pixel budgets, retention periods, thresholds, and repeated semantic strings. Record each value's current owner and consumers.
2. Inspect the existing centralized configuration owner, including `app/configuration_defaults.php`, before introducing any new registry. Distinguish immutable constants from configurable defaults; centralization must not accidentally make a security invariant administrator-editable.
3. Reconcile the existing documentation explicitly: `app/configuration_defaults.php` currently excludes protocol constants, schema versions, enums, and format hard limits. Define their centralized immutable ownership without silently mixing them into the writable/default configuration map. Update conflicting architecture guidance as part of the selected work.
4. Reuse an existing semantic constant where appropriate. Equal numbers with different meanings need separate names, not one ambiguous shared constant. Do not manufacture meaningless constants for language syntax, loop initialization, or unrelated fixture examples.
5. Document each definition with a descriptive comment/docblock, type, units, allowed range where applicable, intended consumers, and compatibility/security implications. Remove obsolete local definitions after migrating callers.
6. Deliver browser-safe values through an established asset/view-model mechanism; never expose the entire server configuration or secrets. Preserve dependency-free early-runtime guards and standalone tooling through an appropriate minimal constants interface.
7. Extend central-audit coverage to detect new unexplained policy literals and duplicate definitions, with reviewed semantic rules rather than a blanket digit-search that produces thousands of false positives.

### Success criteria

- Meaningful operational values have one explicit centralized source and documented semantics.
- Existing callers use the named definitions; intentional behavior and defaults remain unchanged.
- No secret leakage, bootstrap dependency cycle, runtime configuration fetch storm, or unrelated schema rewrite is introduced.
- The inventory records reviewed exceptions explicitly; a few sampled files are not described as a whole-codebase audit.

## Priority 13 - Verify the Rudolf Klusal author header throughout first-party source

**Type:** explicit maintainer requirement; attribution and source conventions. **Status:** requested whole-tree verification, not yet performed.

### Required outcome

Double-check every applicable first-party source file against the repository's documented header format, including the exact author attribution `Rudolf Klusal`. Correct missing or malformed headers and keep them in place for future files.

### Implementation steps

1. Reuse the documented source-header format and `tests/source_header_author_test.php`. Review its discovery and exclusions so modules, split part files, entrypoints, scripts, fixtures, migrations, JavaScript module variants, styles, and Windows tooling are not accidentally omitted.
2. Check the complete header, not merely whether the author's name appears somewhere: project, file/module identity, purpose/responsibilities, attribution, and other fields required by the applicable existing template.
3. Preserve existing useful comments, license notices, shebang placement, PHP declaration rules, and third-party attribution. Do not relabel vendored code as Rudolf Klusal's work.
4. Use valid language-native comments. For JSON, binaries, generated artifacts, and other formats that cannot carry a source header safely, document their attribution through the owning source/generator or the repository's established metadata convention.
5. Extend the existing central-audit contract for uncovered first-party file types instead of adding a parallel manual checker.

### Success criteria

- Every applicable first-party source file has the correct header and author attribution.
- The coverage/exclusion inventory is explicit and justified, including generated and third-party files.
- No historical license is removed, executable behavior changes, or file format is broken just to add a header.

## Priority 14 - Re-audit and enforce strict MVC across the complete runtime

**Type:** explicit maintainer requirement; mandatory architecture, not an optional style preference. **Status:** existing strict checks pass historically; a new whole-runtime responsibility review is requested.

### Required outcome

Maintain the canonical Controller -> Service -> Model direction. No new responsibility leakage is acceptable, and existing architectural candidates must be inspected rather than assuming that a green pattern-based check proves complete MVC correctness.

### Implementation steps

1. Use `scripts/check_mvc_boundaries.php` and its runtime inventory. Review the 27 advisory candidates reported by the previous release audit individually; they are review candidates, not 27 already-proven violations.
2. Trace actual ownership: models own SQL/PDO, persistence and row mapping; services own reusable validation, policy and filesystem orchestration; controllers own request/authentication/CSRF/response boundaries and prepared view models; views own presentation only.
3. Review generic helpers and bootstrap modules too, so domain logic or SQL is not merely moved outside scanned MVC directories to evade enforcement.
4. Migrate complete vertical responsibilities while preserving public include contracts, useful comments, authorization, schema laziness, and mutation semantics. Pass semantic inputs across layers, never SQL fragments.
5. When splitting modules, retain the entry-point contract and rebase moved filesystem expressions for their new directory depth.
6. Strengthen the existing scanner/contracts for proven gaps. Keep the strict baseline empty; never add an exemption or baseline entry merely to make a violation pass.

### Success criteria

- Each advisory candidate has a recorded ownership decision and any required correction.
- Source review and runtime contracts agree on MVC boundaries, with zero strict violations.
- Views consume prepared data and do not discover domain policy or mutate state.
- Every other selected improvement in this document obeys MVC from its first implementation step.

## Priority 15 - Document every function, method, class, and data object

**Type:** explicit maintainer requirement; repository-wide maintainability. **Status:** broader documentation coverage requested, not yet verified.

### Required outcome

Every first-party function, method, and class must have a meaningful docblock/docstring. Data objects and their fields must have documented shapes and semantics. Use the existing `@param`-style PHPDoc/JSDoc conventions and appropriate language-native equivalents; a generic “handles this operation” sentence is not sufficient.

### Implementation steps

1. Review `tests/function_documentation_test.php` and its current PHP/JavaScript discovery. Extend coverage to classes, constructors, methods, relevant anonymous callbacks/arrow functions, exported APIs, data-object declarations, and supported script/module file types.
2. Document purpose and observable behavior, typed/named `@param` entries matching the signature, return type/shape with `@return` or the language's established equivalent, and meaningful exceptions, side effects, authorization preconditions, or lifecycle ownership.
3. Document classes with their responsibility, invariants, collaborators, and lifecycle. Cover constructors and private methods too; visibility is not a documentation exemption.
4. Define reusable data-object shapes for mutation envelopes, view models, queue/job state, configuration records, and other structured payloads. Explain field types, optionality, units, allowed values, null semantics, and sensitive fields. Reuse canonical shape definitions rather than copying drifting field lists between consumers.
5. Preserve existing correct documentation and improve inaccurate or empty boilerplate. Do not invent guarantees that the implementation does not provide, or expose credentials/private examples in comments.
6. Make verification syntax-aware where possible. Check declaration coverage and parameter/signature agreement, then manually review semantic quality; comment presence alone cannot prove documentation is useful.

### Success criteria

- All in-scope declarations and data shapes are covered, including previously unscanned language forms.
- Parameter names/types and return shapes agree with implementation; key invariants and side effects are explained.
- New undocumented declarations fail through the central audit.
- Documentation changes do not silently alter application behavior, generate a new framework dependency, or weaken existing tests.
