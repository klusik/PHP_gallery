# Gallery title-picture selection

Gallery cards reuse the existing `gallery_cover_image()`,
`gallery_cover_collage_images()`, thumbnail bundles and `gallery-collage`
markup. There is no separate preview renderer.

## Persistence contract

The migration `202610140002_gallery_cover_image_ids.php` adds the optional
`galleries.cover_image_ids` text field.

- SQL `NULL`: legacy cover behavior. A selected `cover_image_id` renders one
  cover; a photo-less parent uses its existing child-gallery collage.
- JSON `[]`: explicit **Automatic** mode. Deterministically choose up to two
  direct photos, then fill missing slots from accessible child galleries.
  Candidate order is stable. Public rendering rechecks publication and current
  gallery access; authenticated Admin rendering may include unpublished photos.
- JSON `[101,102]`: blue-star multi-selection, ordered by selection time.
  Each ID is revalidated for direct ownership and visibility during rendering.
  The pre-existing four-photo collage limit also applies to manual selections.
- `cover_image_id`: retain the first selected ID for legacy consumers and
  postconditions. The explicit selection list is authoritative for collage cards.
- `cover_image_path`: existing uploaded cover asset retains priority unless
  the administrator explicitly chooses a star, which switches back to photo
  covers.

The sidecar stores `cover_images` as gallery-relative filenames (empty list for
Automatic) so a file-based import can remap IDs on another installation. The
existing ZIP migration manifest also stores
`cover_selection_source_ids` and restores those selections only after the
normal image ID mapping completes; no parallel import pipeline is introduced.
Applying either explicit photo selection clears `cover_image_path` in the same
gallery update whenever that field is present in the freshly loaded row, so an
inconclusive secondary asset-schema probe cannot leave an uploaded cover
shadowing the imported photo choice. Rows from older schemas without that
field remain untouched.
Missing or moved photos are ignored. Empty visible manual selections fall back
to the same bounded automatic cover logic without revealing private data.

## Interaction

In **Edit gallery > Images**: an outline star selects only that photo (gold);
click the current gold star again to enter blue accumulation mode; clicking any
outline star in blue mode adds it; clicking any selected blue star resets to that
photo alone (gold). The existing image-bulk endpoint receives atomic semantic
actions `cover`, `cover_arm`, `cover_add` and `cover_reset`. The drawer uses
its existing AJAX form owner, CSRF and public mutation coordinator. No separate
Save button is required.

**Bulk action > Set as title picture** also accepts one to four checked
photos. One checked photo makes an exclusive gold star; two to four photos
persist an ordered blue selection in one locked gallery update. No checked
photos or invalid/moved photos produce a bounded, logged validation error rather
than silently replacing the gallery cover with a partial selection.

The star completion updates the affected row controls in place, then runs the
existing canonical completion flow. It does not replace the whole editor panel;
the open tab, scroll position, other form drafts, and already loaded/preloaded
image rows remain available. The public gallery is refreshed through its
existing fragment owner. If the response is lost or reports an error, the
browser first makes a passive no-store GET for authoritative cover state. A
changed revision is reconciled in the mounted editor before public completion;
if reconciliation cannot prove a changed state, read the current editor state
before retrying. An HTTP 500 can happen after the database and sidecar write.

## Failure diagnostics

A title-picture POST failure records event `gallery.cover_update_failed` in
**Admin > Logs**, under category **Gallery**, severity **Error**. The structured
context includes the request reference, gallery and selected photo IDs,
submitted action, failure phase (validation, persistence, or response building),
exception type, bounded error reason, source file/line and HTTP status.
The same reference and diagnostic summary go to the PHP error log even when
the Admin log database is unavailable. Browser responses show a safe reference
rather than a raw exception or SQL error, with HTTP 422 for invalid selections,
409 for stale/conflicting writers, 503 for missing migrations and 500 for
unexpected failures.

The side-panel entry, operations facade, side-panel module and star module
all use refreshed import revisions. The panel independently derives the
clicked star action from current server-rendered attributes and consumes its
submitter fallback exactly once. Therefore a cached legacy bulk handler cannot
mistake clicking the current gold star for an empty checkbox selection after
the next deployment.

The cover writer and passive reconciliation read the form destination from its
`action` attribute (or the panel's prepared endpoint). A submit button named
`action` can shadow the DOM `form.action` property; reading the attribute keeps
both the dynamically prepared drawer and the standalone Images form on their
actual bulk endpoint.

In **Edit gallery > Media > Title picture**, choose **Automatic (2 photos)** to enable
automatic composition. **Default (original gallery behavior)** retains the
pre-upgrade fallback and does not silently change when saving other settings. The multi-selection appears as a selected read-only
option until the administrator intentionally chooses Automatic or another
single picture. Saving unrelated settings therefore keeps all blue stars.

The renderer presents the existing two-column collage layout. The public
mutation postcondition checks a cover-selection fingerprint, not just the
primary cover image ID, so changing blue selections with the same primary
photo refreshes public content correctly.

The gallery edit writer lease serializes selection changes and the public
completion marker fingerprints the primary ID, ordered selection JSON, and
uploaded-cover path. A refresh therefore detects selection changes even when
the first selected photo remains the same. Missing migration or unknown schema
readiness refuses multi-selection writes before persistence; legacy single
cover behavior remains available.

## Verification

`tests/admin_side_panel_delegation/cover_stars.mjs` extends the assertions
consumed by the existing registered
`tests/admin_side_panel_delegation_test.mjs` suite. The HTTP/browser lifecycle
fixture `tests/support/admin_gallery_cover_stars_browser.mjs` is consumed by
the existing registered `tests/admin_panel_lifecycle_browser_test.mjs` suite
for its default lifecycle fixture. These additions extend existing audit-owned
suites; they do not add a YAML test owner or alter the protected evaluator
registry. `tests/gallery_cover_selection_test.php` exercises bounded SQL order,
legacy fallback, photo ownership, publication filtering, protected children,
automatic direct/child selection and stale IDs. The real HTTP cycle in
`tests/gallery_edit_concurrency_mysql_test.php` verifies the cover write and
authoritative response against the existing disposable database/HTTP fixture.
`tests/gallery_cover_transfer_test.php` executes the sidecar and resumable
transfer owners with isolated adapters, including ordered ID remapping and
missing/unknown schema refusal.

An acknowledged cover write advances the mounted settings revision only when
its `previous_edit_revision` matches that form's current snapshot. Passive
reconciliation preserves the settings revision: another session's fields must
remain protected by the existing editor conflict review.

Isolated runtime closure coverage checks that the selected Admin bulk route
loads its cover service from authored runtime dependency inputs. Hosted
candidate preparation generates the checked-in runtime plan; no local
generated-file edit is authoritative. These fixtures extend existing
regression owners; their presence does not claim a passing run or hosted
qualification.

The full gallery editor reuses the existing
`domain-admin-galleries-edit-metadata` owner. Its complete closure, including
cover selection, already belongs to the editor. This authored dependency
removes duplicated metadata files without adding a module or widening any PHP
file closure; the module and ownership ratchets remain unchanged.

## Selective route module loading (2026-10-14 regression)

The normal HTTP entrypoint uses `app/runtime/modules.php`, **not** the
full `app/services.php` umbrella. The `admin_bulk_images` route must include
`app/services/gallery_covers.php` in its compiled module closure before it
uses `gallery_cover_selection_mode()`, `gallery_cover_selection_ids()`, or
`gallery_cover_selection_revision()`. Missing registration caused an HTTP 500
during both `persist_cover` and `build_success_response`, even though BASIC
PHP syntax checks and stand-alone cover tests succeeded.

`tests/runtime_module_plan_test.php` now asserts the image bulk route includes
the cover service, while `tests/support/runtime_module_fixture.php` verifies
that these functions exist **after actual isolated module preparation**. Add
dependencies to the authored runtime plan inputs and let hosted candidate
preparation regenerate runtime loading plans with new service dependencies;
never edit generated `app/runtime/modules.php` as an authored input, and never
assume a passing BASIC workflow qualifies the actual HTTP runtime.
