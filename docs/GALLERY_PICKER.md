# Bounded parent and move-destination picker

Author: Rudolf Klusal

The destination picker uses its own authenticated search endpoint. It is separate
from optional gallery-title completion: selecting a destination commits an
existing physical gallery ID. Typed text never commits an ID. Root is an explicit
action only when the caller enables it.

## Ownership and budgets

`app/controllers/admin_gallery_renderers.php` prepares selection, labels, endpoint
URLs and fallback context. `app/services/gallery_picker.php` owns semantic query
validation, normalized destination records and the existing picker compatibility
entry points. `app/models/gallery_picker_search.php` owns parameterized SQL;
`app/models.php` registers it. `app/views/admin_gallery_renderers.php` renders only
prepared data. No schema change or new feature setting is required.

The Core policy `GALLERY_PICKER_SEARCH_PAGE_SIZE` fixes search pages at 30 rows. No
request parameter can raise the limit. Initial markup contains at most 30 physical
result buttons and one optional root action. The committed gallery is queried
independently and displayed even when absent from the current page. Full relative
paths and explicit IDs distinguish duplicate names. Selected context never needs
a whole ancestor tree: the stored relative path supplies the ancestry.

A normal search performs one limited query. An off-page selection adds one
`LIMIT 1` lookup; excluding a parent-move branch adds one source lookup. Initial
photo-move rendering can add one optional off-page child-hint lookup, without
committing its ID. It does not simultaneously load a source branch. The initial
parent/move paths therefore materialize at most 32 physical rows, including
context, independent of catalog size.

All eight PHP picker limits are defined once in `app/policy_constants.php`, in
`Gallery\Core`, and imported by their model/service/controller consumers. Each
definition documents type, units, scope, consumers and rationale. The browser's
200 ms debounce, maximum visual indentation and decimal-ID pattern are owned by
`public/assets/gallery-modules/gallery-picker-policy.js`. Row limits continue to
come from the server rather than a duplicated browser page-size constant.

The additional fixed budgets are:

| Budget | Limit |
| --- | ---: |
| Search text | 255 Unicode characters / 1,024 UTF-8 bytes |
| Stored title | 255 characters / 1,024 bytes |
| Stored relative path | 1,024 characters / 4,096 bytes |
| Encoded JSON response | 512 KiB |
| Complete canonical picker HTML | 1 MiB |

Malformed/oversized values fail safely. The controller enforces the final HTML
and JSON ceilings. Empty search browses in ascending ID order. Continuation uses
exclusive `after_id`, never OFFSET or a whole-catalog count. A full page offers
continuation without an extra lookahead row, so the final continuation may be
empty. Pages replace the existing result buttons rather than accumulating them.

Search is a literal title or relative-path substring. SQL LIKE metacharacters
`%`, `_` and `!` are escaped as data. Matching follows database collation; it
does not promise JavaScript NFD/accent-normalization equivalence or the old
client-side relevance ordering. Stable ID order makes pagination deterministic.
All destinations remain reachable by ordinary directory browsing.

## HTTP contract

Register `admin_gallery_picker_search` to
`Gallery\\Controllers\\cms_admin_gallery_picker_search()`. GET accepts:

- `q`: literal search text; blank to browse.
- `after_id`: exclusive ID cursor, default zero.
- `selected_id`: independently committed context ID, default zero.
- `excluded_id`: source gallery to omit, default zero.
- `exclude_descendants`: exactly 0 or 1; use 1 for physical parent moves.
- `allow_root`: exactly 0 or 1; controls the HTML directory root explanation.
- `format`: `json` (default) or `html` (standalone directory lookup).

Identifiers must be canonical nonnegative decimal strings representable by PHP.
Arrays, negative/leading-zero values, overflow and malformed UTF-8 are refused.
Only an administrator may enumerate results. Anonymous/viewer requests receive
401, non-GET administrator requests 405 with `Allow: GET`, invalid requests 400,
and lookup failures 503. Responses are private/no-store, nosniff and noindex.
Exception details, access fields, tokens and absolute storage paths are absent.

The JSON envelope is `{ok, rows, selected, next_after_id, more, page_size}`.
Each row contains `id, title, path, depth, label, selected`. JSON row/context IDs
and continuation IDs are decimal strings, preserving BIGINT identity above
JavaScript's safe-number limit. `next_after_id` is null when no further page is
offered. Root is a local static action, not a fabricated physical gallery row.

## Commitment and no-JavaScript use

The browser enables the canonical hidden form field and emits its existing
`input`/`change` events on commitment or clearing. Editing text clears a previous
ID immediately. An optional prefilled child label is only a suggestion until
clicked or accepted. Search/page changes leave an existing committed ID intact.
An optional search failure while rendering does not silently clear the existing
parent ID; its bounded ID label remains available.

Parent inputs use native form validity to require an explicit gallery/root
commitment after text edits, so uncommitted text cannot silently become root on
submission. Workflow-owned disabled fields remain disabled when the picker binds.

Requests debounce at 200 ms. Every query, close, selection, outside click and
fragment disposal invalidates older requests. Aborting is supplemented by an
ownership generation check. IME interim text does not search. Arrow navigation,
Enter and pointer events act only on the current page; modified keys are
preserved. Escape dismisses the control before a second Escape reaches the
drawer. A single mutation observer binds injected fragments and disposes detached
owners. These read-only picker actions never submit a mutation, navigate, reload
or close the containing panel.

Without JavaScript, the disabled hidden input does not submit. A visible
`noscript` numeric field submits the same parameter name. The directory link
opens a separate tab, keeping the unfinished form intact. Its ordinary GET form
searches the same bounded catalog; Next results traverses the same keyset.
Administrators copy the chosen ID into the original form. Parent controls explain
that ID 0 means no parent. Existing JSON-only Picture manager workflows still
require JavaScript; the directory lookup does not add a second mutation route.

The lookup is discovery, never mutation authorization. Physical self/descendant
checks, existence/path/schema checks and Smart Gallery graph validation remain in
`move_gallery_folder_to_parent()`, its existing creation/move callers, and
`smart_gallery_validate_gallery_parent_change()`. Photo moves omit only their
source, because its children are legitimate destinations. Parent moves omit
their entire source path branch. The search does not load the Smart Gallery
graph per candidate: a structurally invalid Smart relationship can still appear
in discovery and must be rejected by the canonical mutation service.

## Synthetic evidence

`tests/gallery_picker_search_test.php` executes the real new model SQL, service,
HTTP controller and renderer against private `sqlite::memory:` fixtures. It
never loads the application bootstrap, config or live database. The baseline
renderer is frozen in `tests/support/gallery_picker_legacy_view.php`; the former
row mapping is reconstructed with the same fixture rows. This compares actual
old/new picker markup, not complete application pages.

Measured on 2026-09-20 with PHP 8.3.30; deterministic duplicate titles and deep
paths are included. Element counts come from PHP DOM parsing, including its
document wrappers. One render was timed for each sample, so the elapsed times
below are diagnostic samples, not stable latency estimates.

| Galleries | Old HTML | New HTML | Old/new result buttons | Old/new element nodes | Old/new peak extra PHP bytes |
| ---: | ---: | ---: | ---: | ---: | ---: |
| 100 | 59,226 B | 15,368 B | 100 / 31 | 310 / 107 | 134,968 / 66,488 |
| 1,000 | 594,192 B | 15,374 B | 1,000 / 31 | 3,010 / 107 | 1,325,496 / 66,456 |
| 10,000 | 6,035,598 B | 15,380 B | 10,000 / 31 | 30,010 / 107 | 13,745,296 / 66,456 |

The recorded development sample took 0.630/0.254 ms, 5.325/0.363 ms and
53.373/0.294 ms for old/new rendering respectively. Measurements include
querying, row preparation and HTML serialization; fixture creation and DOM
parsing are excluded from timing/memory. Peak allocation uses
`memory_reset_peak_usage()` when available, otherwise reports null.

EXPLAIN QUERY PLAN on the actual generated search SQL reports
`SEARCH galleries USING INTEGER PRIMARY KEY (rowid>?)` in SQLite. This is a
returned-row/application-memory bound, **not a database examined-row or wall-clock
bound**. An infix miss may examine the remaining catalog. No production
MySQL/MariaDB query plan, live database latency, browser painting/input-filter
timing or assistive-technology behavior was measured.

The new `tests/gallery_picker_browser_test.mjs` executes the actual browser
module with a deterministic Node DOM/event seam. It covers debounce, ignored
aborts, stale ownership, off-page commitment, root, large exact IDs, IME,
pagination replacement, modified keys, Escape and replaced-fragment disposal.
It is not a real-browser rendering test.

## Form integration

The current source mappings preserve MVC preparation and the original submitted
field names:

| Surface | Controller preparation | View consumption |
| --- | --- | --- |
| New gallery / new-gallery upload | Discovery render helpers add `parent_picker_html = render_gallery_parent_picker($prefillParentId)` | Both branches of `admin_gallery_forms.php` render the prepared fragment |
| Edit gallery parent | `tab_identity.php` prepares `render_gallery_parent_picker((int) ($gallery['parent_id'] ?? 0), (int) $gallery['id'])` | `admin_gallery_edit_tabs.php` renders `parent_picker_html` |
| Bulk move to a new gallery | `admin_galleries_edit_views.php` prepares `render_gallery_parent_picker($galleryId, 0, 'new_gallery_parent_id')` | `admin_gallery_edit_components.php` renders `parent_picker_html` |
| Bulk move to an existing gallery | Existing renderer uses `destination_gallery_id` and excludes the source gallery | The same prepared bounded picker fragment remains in place |

The P3 and replay owners implemented the edit/create form mappings; their source
was inspected without editing those owned files. New-gallery upload uses the
same discovery render helper. Unrelated existing-gallery upload, discovery and
WebDAV selects remain legacy catalog selectors, outside this parent/move slice.

`admin-gallery-title-completion.js` resolves
`input[type="hidden"][name="parent_id"]:enabled, select[name="parent_id"]:enabled`
both when reading a title's parent scope and handling delegated parent changes.
The browser-owned `admin-side-panel.js` serializer likewise accepts an enabled
hidden `new_gallery_parent_id` and retains its canonical mutation envelope.

The endpoint/loader and English/Czech translation registrations were completed by
the parent. The browser entrypoint uses these current imports:

- `searchable-gallery-picker.js?v=20260920-bounded-gallery-picker-v2`
- `admin-gallery-title-completion.js?v=20260920-gallery-title-completion-parent-picker-v3`

The picker imports
`gallery-picker-policy.js?v=20260920-picker-policy-v1`. Changed browser modules
must continue to receive cache-busting import updates.

## Current browser evidence and audit registration

`tests/gallery_picker_parent_integration_browser_test.mjs` renders the current
PHP parent and bulk controls using the disposable SQLite fixture, then runs the
current picker, policy and title-completion modules in a private Chromium profile.
It passed on 2026-09-20 using the installed Microsoft Edge executable.

Its real DOM assertions cover bounded initial result nodes, enabled hidden-field
selection, exclusion of a disabled duplicate, enabled legacy-select fallback,
delegated title requests with the newly committed parent, exact FormData IDs,
explicit root selection, native validity after uncommitted text, and replacement
of the form fragment without changing the URL or removing its containing panel.
The fixture fetch responses are synthetic; it does not execute a real Admin save,
authentication lifecycle or a filesystem move.

For source-checkout development, the runner accepts Chromium and PHP executables:

```text
node tests/gallery_picker_parent_integration_browser_test.mjs CHROMIUM_PATH PHP_PATH
```

The optional PHP argument defaults to `PHP_GALLERY_PHP`, then `php` on PATH.
Missing Chromium/PHP or missing fixture PHP extensions reports SKIP. Source or
assertion failures are failures, not skips.

The parent registered this separate Chromium entry in
`scripts/audit_registry.php`:

```php
'gallery_picker_parent_integration_browser_test.mjs' => [
    'browser' => true,
    'timeout' => 60,
],
```

Keep `'gallery_picker_browser_test.mjs' => []` as the already registered Node
seam; it does not require Chromium. The PHP regression fixture also now renders
the actual bulk toolbar and asserts that no full new-parent select is present.

The subsequent browser policy migration is inventoried in
[FRONTEND_OPERATIONAL_POLICY.md](FRONTEND_OPERATIONAL_POLICY.md). Title completion
now imports `admin-interaction-policy.js`; this fixture's allowlisted server
serves that dependency. The parent central run owns verification of this newer
asset graph; the earlier recorded Chromium pass is not relabeled as a rerun.

Only the three picker development fixtures were run by this agent during this
follow-up. The parent owns the central audit and manifest. The reported missing
Project headers were corrected in the two original fixture entrypoints and
`tests/support/gallery_picker_legacy_view.php`.

Remaining acceptance limits are a real authenticated create/edit/save and move
journey after all agents' integration, JavaScript-disabled directory lookup plus
ID submission, and production MySQL/MariaDB query-plan/latency measurement. The
current Chromium fixture proves the rendered control/module contracts, not those
whole-application journeys. Existing physical/Smart Gallery mutation cycle
coverage remains part of the parent's central audit.
