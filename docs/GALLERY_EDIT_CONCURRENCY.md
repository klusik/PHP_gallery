# Gallery edit concurrency

Author: Rudolf Klusal

## Entity and protocol

The protected entity is the base `galleries` row. The full editor renders a
decimal `edit_revision` from the exact row used for its fields. It never fetches
a newer revision after preparing older values. This is not a timestamp,
credential, authorization token or public-refresh revision.

`app/services/gallery_edit_concurrency.php` owns refusal policy and secret-free
comparison. `app/models/gallery_edit_concurrency.php` owns SQL and operation
ownership. Migration `202609200002_gallery_edit_revision.php` adds an unsigned
BIGINT revision using an ordinary table alteration. It creates no triggers,
stored routines or server-global settings and needs no elevated database role.

The save acquires a database-specific named lock, starts a short transaction,
reads the target with `FOR UPDATE`, compares the submitted revision, and commits
a revision reservation. Only then may folder, asset, tag, flight-map,
localization, Smart Gallery assignment and sidecar work begin. The named lock
remains held through those operations; no outer PDO transaction remains open.
The row lock provides an exact compare-and-reserve boundary for the submitted
row.

Every supported application gallery UPDATE explicitly increments the revision,
including bulk setters, access changes, public-path rebuilds and cover writers.
The shared application lock is required before mutable reads through the last
database/file side effect. The inventory below identifies implemented wrappers
and known direct paths that do not yet satisfy this lifetime.
`Gallery\Core\GALLERY_EDIT_LOCK_SUFFIX` in `app/policy_constants.php` owns the
protocol suffix used by those application writer boundaries.

`gallery_edit_model_lock()` and `gallery_edit_model_release()` accept an
optional
explicit PDO connection. Ordinary writers retain the configured connection;
the layout migration supplies its runner connection for both calls. This keeps
the same database-specific lock and nonblocking policy available to
`install.php`
before `config.php` exists. Missing ownership or a busy peer refuses the
callback
before layout writes; it never skips the lock for a fresh installation.

This deliberately serializes operations across the whole installation,
including unrelated galleries. Public-path rebuilds can invalidate forms for
several galleries. A validation failure after reservation also invalidates the
old form. These are conservative conflicts, never permission to merge or retry
old settings automatically.

The lock is recursive on one connection and survives commits/rollbacks. Each
acquisition requires its own `finally` release. All writers must target the same
primary server; this is not coordination across multiple writable replicas or
NDB. See
[MySQL locking functions](https://dev.mysql.com/doc/refman/8.4/en/locking-functions.html).

A killed worker loses its named lock when its connection terminates, while the
committed reservation remains. This avoids adding an outer-transaction rollback
of database paths after filesystem work. It does not make existing multi-step
filesystem workflows crash-atomic; their journal/recovery owners retain that
duty.

## HTTP and browser ownership

`admin_edit_gallery_success_response()` preserves the canonical mutation
envelope
and adds top-level `edit_revision`, a decimal string from the saved row. Update
only the submitting form under its existing form/gallery/generation ownership
check, before awaiting mutation-completion refresh. An old success must never
overwrite a newer draft or another gallery's token.

Stale, missing or malformed form revisions return HTTP 409 with
`error_code: gallery_edit_conflict`. Active ownership uses the same code with
`conflict.busy: true`. The conflict object contains `gallery_id`, `busy`,
`latest` and `reload_url`. `latest` is an explicit non-secret projection, never
password hashes, share tokens, token hashes or submitted passwords. JSON leaves
the entered form in place.

The no-JavaScript response also uses 409 and displays entered non-secret values
alongside stored values. A latest-editor link opens another tab. Copy selected
changes after review; re-enter passwords and reselect uploads. Access settings
are never automatically merged. The stale form receives no new retry authority.

Full forms require their rendered revision. Partial inline actions without a
token compare the row read by that request. They preserve partial-field
semantics but do not protect a historic browser snapshot unless the caller
supplies `edit_revision`.

## Required filesystem writer boundaries

Acquire `gallery_edit_writer_begin()` before reading mutable state and before
the outer use case's first target file change. Retain ownership through metadata
and sidecars, and release through `gallery_edit_writer_end()` in `finally`.
Acquire before more specific pair/job/entity locks where the outer owner has
that contract. Existing upload and linked-create ordering exceptions are
documented below; they must be reconciled before filesystem-fence activation.
Same-connection nested callers may reacquire. Do not open an outer PDO
transaction around the use case.

The wrappers below are implemented. The shared test inventory verifies ordinary
wrapper lifetimes and actual busy refusal; this is not evidence that every
successful workflow, browser route or crash-recovery path has been exercised.

| Owner | Required outer functions | Covered effects |
| --- | --- | --- |
| `gallery_sidecars.php` | `create_empty_gallery`, `create_gallery_row_for_folder`, `normalize_gallery_sidecar_tags_recursive` | Folder/sidecar creation, catalog maintenance and sidecar-only tag normalization. |
| `gallery_mutations.php` | `delete_gallery_subtrees`, `delete_gallery_images`, `move_gallery_folder_to_parent`, `import_galleries`, `import_galleries_without_thumbnails` | File staging/removal/moves before row cleanup/path rewrite; scans and paths. |
| `gallery_mutations.php`, `gallery_image_move_journal.php` | `move_gallery_images` and journal recovery | Global ownership before pair locks, retained through completion/recovery. |
| `uploads.php` | `store_uploaded_gallery_images`, `store_uploaded_gallery_cover`, `store_uploaded_gallery_branding_asset` | File placement before scanning/gallery persistence. |
| `browser_uploads/pipeline.php` | `browser_upload_store_prepared_zip_batch` | Original/derivative installation before scanning and cover maintenance. |
| `mobile_webdav.php` | `mobile_webdav_store_put` | PUT replacement before gallery scanning. |
| `image_scanning.php` | `scan_gallery_images`, `scan_gallery_selected_images`, `scan_gallery_selected_uploaded_images` | Derivative invalidation before cover/path maintenance in `scan_gallery_refresh_after_changes`. |
| `gallery_trash.php` | `move_gallery_subtrees_to_trash`, `restore_gallery_trash_entry`, `gallery_trash_reconcile_entry` | Direct Trash routes and recovery can move live directories independently. |
| `picture_manager.php` | `copy_gallery_images`, `picture_manager_copy_gallery_subtrees` | Copying files before cover/import/scan/sidecar updates. |
| `media_renamer.php` | `media_renamer_execute_gallery`, `media_renamer_execute_gallery_image_batch`, `media_renamer_execute_image_batch`, `media_renamer_execute_galleries`, `media_renamer_execute_plan` | Ownership spans planning as well as staging/rename and metadata; plans cannot be prepared from an earlier folder location. |
| `gallery_branding.php` | `delete_gallery_branding_asset` | Removes a file before clearing the gallery column. |
| `gallery_migration/target_setup.php` | `gallery_migration_prepare_target_job` | Creates target tree and applies metadata after storing resumable state. |
| `gallery_migration/packages.php`, `install.php` | `gallery_migration_install_package_file`, `gallery_migration_install_asset_file` | Installs target files before metadata and job progress. |
| `gallery_migration/install.php`, `jobs.php` | `gallery_migration_sync_received_assets`, `gallery_migration_job_status_response`, `gallery_migration_complete_job` | Ownership precedes job loading; status/recovery can repair metadata and completion updates covers/paths. |
| `gallery_editor_mutations.php`, `gallery_dates.php`, `thumbnail_bounds.php`, `gallery_grid.php` | `gallery_editor_set_cover_image`, `gallery_date_save_range`, `save_gallery_thumbnail_bounds`, `reset_all_gallery_grid_overrides` | SQL-first writers need ownership through subsequent sidecars to avoid publishing an older row. |

Internal migration helpers are covered only if every caller uses the guarded
outer entry points. Preserve original docs, module includes and filesystem
depth.
Theme-only assets do not write gallery rows and are outside this entity
boundary.

The original implementation bodies remain in their original files as `*_owned`
delegates. Public wrappers acquire first and release in `finally`, including
early returns and exceptions. The shared concurrency dependency is loaded from
the `browser_uploads.php` and `gallery_migration.php` entry points, not their
part files. No filesystem-relative implementation expressions were relocated.

Scanner, prepared-upload and WebDAV gallery reads explicitly bypass the
request-local row cache after acquisition. Recursive thumbnail-bound saves
reload the target before choosing its current branch. Trash reconciliation
reloads its candidate and refuses a changed lifecycle state before recovery.
Migration status holds ownership before loading the job that synchronization
may rewrite. Synchronization also reloads its own durable job by job ID,
refusing
an unexpected target instead of overwriting progress from a caller's old array.

### Array-input freshness

Acquisition alone does not make an already loaded array current. The boundary
review distinguishes intended input from mutable persistence snapshots:

| Input owner | Under-lock authority |
| --- | --- |
| Cover setter's legacy `fallbackGallery` | Reload gallery and selected image by ID; recheck image ownership before SQL. Reload again for the sidecar. A missing row refuses; the legacy snapshot is never a filesystem fallback. |
| Recursive thumbnail bounds' `gallery` | Use only its ID; reload the gallery before selecting the current descendant branch and reload each row for sidecars. |
| Copy image/gallery selections | Arrays contain semantic IDs, not caller rows. Reload source/destination and selected galleries; image selection explicitly bypasses its request cache and rechecks source ownership. |
| Scanners' relative paths/metadata | Outer APIs take a gallery ID and reload it. Row-taking scanner helpers are called only from those guarded scans, using the freshly loaded row. |
| Migration manifests/assets | These describe intended source content, not authoritative destination rows. Resolve mapped gallery IDs against fresh target rows; thumbnail installation also reloads its image. |
| Migration synchronization's `job` | Use its job ID and expected target; reload durable manifest, mappings and received-asset progress before recovery or save. |
| Trash reconciliation's `entry` | Reload by Trash identity and reject a changed lifecycle status before using paths or recovery metadata. |
| Renamer's `plan` | Reload the complete gallery row and require equality with the planned snapshot. Rebuild from explicit selected image IDs and compare image/source/target items before any rename. Changed plans refuse, without silently replanning the requested result. |
| Upload descriptors and WebDAV credential descriptor | Files/metadata are intended input; destination gallery ID is resolved through a fresh under-lock gallery lookup. Credential authentication remains the existing separate owner. |

The rename planner records `selected_image_ids` even for hidden no-op rows.
Sequence placeholders follow the complete persisted gallery image order, even
for bounded upload/apply selections. A gallery-title change affects the naming
context, not image positions; newly appended uploads keep their later numbers.
Physical staging uses an 83-byte random-hash basename in the original directory,
independent of the source basename. This preserves ordinary rename/rollback and
avoids overflowing a valid long filename by appending temporary metadata.
An empty saved selection stays empty; it cannot expand to newly added images.
Missing selection metadata requires a new preview. Whole-row comparison is
internal only, supports pre-revision rows without timestamp assumptions, and
returns only the shared secret-free projection on conflict.

The inspected thumbnail generation/maintenance services write image/derivative
metadata, not base gallery fields. Their path to gallery writes is through
scanning/cover/path maintenance above. This does not claim all thumbnail file
activity is serialized with a folder rename.

Guarding only `write_gallery_sidecar($oldRow)` cannot make an old argument
current.
Acquire before reading mutable state and retain through writing, or reload and
compare under ownership. Sidecar-only `normalize_gallery_sidecar_tags_recursive`
also retains the outer boundary while traversing and rewriting metadata.

## Visibility marker and filesystem fence inventory

The experimental linked visibility scope remains disabled. The filesystem
fence is preparation for future coordination, not a currently enforced lock.
The database named lock is released on connection loss even if a PHP process
has not finished its filesystem work. A check before a filesystem call cannot
prevent a disconnect between that check and the call. No existing marker, path
check or database lease provides crash-atomic coordination with unrelated
filesystem processes.

Current entry points and existing database-lock lifetimes are:

| Area | Current owner and boundary |
| --- | --- |
| Editor and quick access | `gallery_edit_begin()` calls `gallery_edit_model_reserve()` directly; `gallery_edit_end()` releases after editor effects. Adding a fence only to `gallery_edit_writer_begin()` would miss this path. |
| Ordinary writers | `gallery_edit_writer_begin()` surrounds the wrappers above, including import, scan, create, move, copy, Trash move/restore/reconciliation, editor sidecars, dates, thumbnails, grid reset, media renaming and migration jobs. Owned helpers are not independent write authority. |
| Other guarded jobs | `gallery_consistency/repairs.php`, `gallery_image_move_journal.php`, `browser_thumbnail_rebuild.php`, `gallery_pdf_conversion.php` and `simbrief_ofp_conversion.php` also have explicit writer boundaries. Image moves acquire the global database lock before their sorted pair locks. The ordinary wrapper test inventory alone does not cover every entry. |
| Linked creation and visibility | `app_linking_create.php` acquires the writer lock around each creation or disabled visibility mutation. Creation also has a durable operation claim. Visibility retains a marker, but marker recovery and competing writers are not yet integrated. |
| Legacy and linked upload | Both controllers use `upload_automation_store_images()`: it takes a gallery-specific database lock before `store_uploaded_gallery_images()` and its global writer lock. Linked post-store work remains under both; legacy post-store work runs after the global lock is released. The legacy controller additionally generates derivatives after the upload service returns. |
| Layout compatibility | `gallery_description_layout_migrate_legacy()` directly acquires `gallery_edit_model_lock($pdo)` on the migration connection before planning and applying live/Trash sidecars. `gallery_description_layout_upgrade_sidecars()` has no such wrapper itself. |

Known paths requiring an outer boundary before their first authoritative change
include the following. A filesystem lock added only to the ordinary wrapper
would not cover them.

- `cms_admin_reorder_galleries()` calls individually guarded folder moves,
  then updates order and refreshes sidecars outside those nested leases.
- `admin_gallery_discovery_move_requested_photos()` and
  `admin_gallery_discovery_delete_requested_paths()` move or delete before a
  possible later guarded scan. Cached discovery results are observations, not
  permission to import or delete without a fresh target check.
- `gallery_bulk_set_gps_map_enabled()` and
  `gallery_bulk_set_show_filenames()` update rows and sidecars without their own
  writer lease. `gallery_bulk_set_visibility()` has one.
- `gallery_metadata_organizer_apply_date_plan()` and its batch variant combine
  individually guarded create/move operations with later unguarded sidecar
  maintenance. Their complete use cases need one consistent boundary.
- `purge_gallery_trash_entry()` claims and removes a Trash payload without the
  gallery writer lease; batch and scheduled purges call it. Reconciliation and
  restore use separate guarded entry points.
- `normalize_gallery_sidecar_tags_recursively()` in `tag_metadata.php` is the
  Admin tag-page repair and differs from the guarded similarly named function
  in `gallery_sidecars.php`. Its legacy configuration key and checkpoint
  compatibility are recorded in [the lifecycle register](COMPATIBILITY_LIFECYCLE.md).

This inventory does not prove that every writer has been found. Scheduled
publication and other SQL-first writers must also check a marker before the
database effect; refusing only the later sidecar write would be too late. Grid
reset similarly updates rows before walking sidecars. Reconciliation,
compensation and error handling must remain inside the ownership boundary.

### Proposed lock-order graph and transition

This is a target to review, not an implemented acquisition order. The fixed
filesystem-fence gate remains false. The outermost filesystem lease must be
obtained before any advisory lock participating in the use case and held
until all protected filesystem and database work, including compensation and
reconciliation, has stopped. The currently identified required edges are:

```text
filesystem lease -> operation-key lock -> global gallery writer
filesystem lease -> upload gallery lock -> global gallery writer
filesystem lease ---------------------> global gallery writer
global gallery writer -> sorted gallery-pair locks, where used
global gallery writer -> transaction and its established row-lock order
```

An arrow means the left owner must already be held before the right owner is
acquired, and released only after the dependent owner and its work finish.
Optional nodes occur only in use cases that need them. This graph does not
establish an order between operation-key, upload-gallery, pair, session,
updater, PDF or other specialized locks in combinations not yet audited. Such
combinations and all nested acquisitions need an explicit cycle-free extension
before activation; missing edges are not authorization to acquire in either
order. Existing grant and user row-lock ordering must also remain intact.

The implementation transition must begin at complete outer use cases, not by
silently inserting a filesystem lock into `gallery_edit_writer_begin()` or
`gallery_edit_begin()`. In particular:

- Linked create and abandon currently take an operation-key lock before the
  global writer. Their outer entry must obtain the filesystem lease before the
  operation-key lock and retain both through the existing completion or
  reconciliation path.
- Legacy and linked uploads, plus the linked recovery probe, currently acquire
  the upload-gallery lock before the global writer. The future upload boundary
  must obtain the same filesystem lease before the gallery lock. For legacy
  upload it must continue through post-store work and controller-side
  `upload_automation_generate_derivatives()`, which currently runs after both
  database leases are released. Linked upload runs its derivatives inside the
  guarded callback. Preserve the existing `pgu_` API response, partial-result
  semantics, authority checks and compatibility throughout this change.
- The layout migration directly uses `gallery_edit_model_lock($pdo)` with the
  migration connection, including installation before normal configuration is
  present. Its outer migration owner needs the same filesystem admission and
  explicit trusted root; ordinary writer wrappers cannot cover that entry.
- An execution must reuse one filesystem-handle owner for nested editor and
  specialized-writer leases. Pair every acquisition with exactly one release;
  the outer handle must remain held while any nested owner or cleanup is active.
  A database disconnect stops subsequent mutations and makes the outcome
  uncertain, but must not prematurely release that filesystem handle while
  already-started protected work can still run. Release and error paths need
  independent verification, including when database unlock fails.

Activation additionally requires a complete inventory of every direct model,
route, worker, scheduled job, updater, CLI and migration entry; consistent
cross-process and cross-host lock-file identity on the actual filesystem; and
a deployment transition that drains all older PHP, CLI and background workers
before any new marker-based write. Unrelated shell, FTP and older-runtime
writers do not participate in `flock`; explicitly exclude or coordinate them.
Restored database and filesystem backups also need independent coherence
assessment. Lock-order tests must cover nested owners, competing upload and
create paths, lost database connections while filesystem work is in progress,
exceptional release, and both upload variants' complete side effects. No
runtime integration or marker-removal authority follows from this plan.

Before considering activation, complete these separate steps:

1. Extend the source and behavioral inventory to route, worker, cron, migration,
   CLI, compensation and recovery callers, including direct model locks. Keep
   the filesystem fence inert during this work.
2. Establish one lock-order graph for operation claims, the retained filesystem
   lease, the global database lease and per-gallery/pair locks. Account for the
   existing upload order and pair reentrant nested calls. Acquire the filesystem
   lease before relevant database gallery leases and retain it from before the
   first effect through the last sidecar, cleanup or reconciliation. Recheck
   authority after acquisition. A failed acquisition or lost database connection
   must not authorize further mutation.
3. Integrate marker checks for logical paths and resolved sidecar targets, plus
   move/copy/Trash subtrees, without silently excluding previously supported
   unmarked symbolic links or truncating work. Refuse uncertain observations;
   check again at authoritative import or mutation, not only a cached preview.
4. Prove that all cooperating processes and hosts use the same persistent lock
   inode on a filesystem with supported interprocess and, where applicable,
   interhost lock semantics. Never unlink or replace it while it may be in use.
   A shared database does not prove a shared filesystem; independently writable
   databases sharing storage need the same fence. NFS/SMB, failover, power
   loss and external writers need separate evidence. Drain old PHP workers,
   cron and CLI jobs and prevent old code from writing that storage before
   enabling a marker-based protocol.
5. Exercise busy, reentrant, crash, connection-loss, partial-write, recovery,
   live/Trash, symlink and multi-host cases through central test owners. Report
   exact hosted results and platform gaps. Keep visibility disabled until the
   complete protocol has independent security review and qualification.

The fence is a server implementation detail. Preserve legacy `pgu_`
authorization and the upload response contract for old and new clients. An old
gallery server cannot enforce a fence or marker protocol it does not implement;
source compatibility does not establish safe mixed-version server writes to
shared storage.

## SQL coverage and foreign keys

The SQL owner in every supported base-gallery model advances
`edit_revision = edit_revision + 1`. Coverage includes
`gallery_model_update_fields`, `gallery_model_update_scalar_for_ids` and its
visibility/GPS/voting/filename/Picture Game helpers, background/grid reset,
cover setters and thumbnail bounds. Other explicit gallery writers are:

- `gallery_order.php`: tree and child ordering.
- `exif.php`: global GPS reset.
- `picture_game.php`: voting synchronization.
- `public_paths.php`: parent assignment and gallery/full regeneration.
- `gallery_mutations.php`: cover cleanup/assignment, paths, hierarchy and
  deletion.
- `gallery_trash.php`: restored metadata and cover.
- `picture_manager.php`: destination-cover assignment.

Access-token generation/revocation and legacy display-token encryption delegate
to the gallery model, so they advance revisions. Render-time legacy encryption
can invalidate the original row: prepare it before capturing the complete form
row, or handle its conservative conflict explicitly.

Foreign-key actions bypass application UPDATE statements. The initial schema
contains
`galleries.parent_id -> galleries.id ON DELETE SET NULL` and
`galleries.cover_image_id -> images.id ON DELETE SET NULL`. Guarded application
deletes must explicitly clear these references through gallery UPDATEs first.
Canonical subtree/image deletion already uses fixed null-dependency lists for
both references; preserve and test that behavior. Direct SQL image deletion,
manual cascades, TRUNCATE, DDL and other out-of-application writes are outside
this
guarantee. See
[MySQL foreign-key actions](https://dev.mysql.com/doc/refman/8.4/en/create-table-foreign-keys.html).

Independent translations, tags, Smart Gallery placements and flight-map rows
do not independently advance the base row unless their application workflow
reserves or updates that row. The main editor serializes its own
dependent operations and first reserves a gallery revision. Specialized writers
of separate entities need explicit revision participation before claiming
lost-update protection for those entities.

## Deployment and bounded health

System Health and Runtime Diagnostics consume the same
`mutation_gallery_edit` registration in
`admin_mutation_schema_health_statuses()`.
It delegates to `gallery_edit_schema_state()`, which uses the shared three-state
inspection result for `galleries.edit_revision`. Missing and unknown inspection
both show Action, a bounded localized explanation and only that fixed affected
identity. Unknown retains ordinary safe request correlation. The Diagnostics
copy report includes the same prepared explanation. These Admin observations do
not add public-request probes or automatic migrations.

The migration is deliberately compatible with common hosted databases. It uses
only `ALTER TABLE ... ADD COLUMN`, never `CREATE TRIGGER`, stored programs,
global-variable changes or SUPER-style privileges. If an earlier attempt added
the column and then failed while creating a trigger, the migration ledger still
lacks the version. Retrying from the Gallery page is safe: the migration runner
accepts the duplicate-column replay and then records the version as complete.
No trigger cleanup is required; a previously installed compatibility trigger
that assigns the same `OLD + 1` revision may coexist with explicit application
SQL.

The updater's plan and immediate activation checks, plus its compatibility copy
entry point, now require actual verified enforcement when the prepared source
contains this feature. They require the ordinary revision column, not a grants
string or privileged database object. Missing/unknown storage leaves the
prepared update recoverable and tells the user to run migrations from the
Gallery administration page. Rollback snapshots predating the feature do not
acquire a new prerequisite.

An older installed updater cannot retroactively execute this new check. For the
first deployment of this feature, use the existing migration action exposed by
the Gallery administration page. No external database administration step is
part of the workflow. Do not publish/deploy this change based on a local fixture
pass alone.
This implementation does not change live schema, grants or configuration.

## Verification

`tests/gallery_edit_concurrency_test.php` covers row-token fidelity, safe
comparison/no-JavaScript rendering and missing/unknown refusal without a DB.
`tests/gallery_revision_runtime_updates_test.php` inventories direct runtime
gallery UPDATE strings across model files, covers the dynamic field/restore/
dependency builders explicitly, and requires an application-owned increment.
`tests/gallery_edit_deployment_preflight_test.php` verifies available, missing
and unknown revision-column states without database metadata/grant inspection.
`tests/gallery_edit_concurrency_mysql_test.php` uses the existing disposable
fixture: portable duplicate-column replay through the actual migration runner,
two cookie-isolated sessions, stale multipart refusal, no-JavaScript review,
durable application reservation, nested locks, timestamp-independent revision
advancement and explicit fresh review/retry.
It also invokes all 37 ordinary filesystem/sidecar writer entry points with a
competing real PDO connection while the editor owns the named lock: every call
must throw the typed busy conflict before reaching its implementation.

`tests/gallery_edit_writer_guards_test.php` reads complete modules and requires
the exact acquire/delegate/`finally`-release structure for those 37 wrappers.
It executes the real wrapper source with local seams for immediate return,
operation failure, acquisition refusal and release failure, and separately
checks journal global-before-pair ordering and the fresh-row reads above.
`tests/support/gallery_edit_writer_inventory.php` owns the shared coverage list.
`tests/gallery_edit_writer_snapshots_test.php` exercises the actual guarded
implementation bodies with explicit in-memory adapters: old/missing gallery
paths, foreign cover/copy images, recursive branch selection, changed/empty
rename plans and durable migration-job progress. No application bootstrap,
database or target filesystem is used by that unit fixture.

PHP regression discovery owns these tests. The MySQL test reports SKIP without
the fixture or BLOCKED when integration is required. The parent owns audit
timeouts, browser completion evidence and final verification. Test existence
does not establish every hosted database, worker-kill scenario or complete
manual
filesystem acceptance. Ad hoc SQL executed outside application-owned model and
writer boundaries is explicitly outside this concurrency contract.

Integration evidence on 2026-09-20: the disposable full audit passed all 234
PHP regressions, including the actual MySQL partial-install recovery with the
column present and migration ledger row absent. It also passed 22 Node, 36
WinApp,
five Chromium integration checks, complete PHP/JavaScript syntax, strict MVC,
changed-declaration documentation and changed-policy contracts with no failures,
skips or blockers. The 708-file managed manifest was regenerated and verified.
