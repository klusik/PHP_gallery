# Gallery editor background Images preparation

The right-hand Admin Edit gallery drawer renders settings before image-row
enumeration, thumbnail URL resolution or table construction. The authenticated
`admin_edit_gallery` GET with `panel=1` supplies a direct-image count and an
Images placeholder. Identity, Access, Display, Media, Documents and API keep
their original forms, revision/CSRF ownership and mutation completion pipeline.
The image-dependent File renamer builds its plan only after its tab is selected.
The ordinary editor URL retains its complete table and no-JavaScript behavior.

## Transport and persistence

The existing authenticated editor route serves read-only `action=images_batch`
JSON. It has no public authorization bypass and does not mutate gallery settings.
The model owns SQL; `gallery_lookup.php` owns the bounded read contract; the
controller normalizes offset, authorizes, renders and sends the response.

Each batch contains `gallery_id`, `offset`, `next_offset`, `total`, `done`,
opaque `revision`, canonical `rows_html`, and first-batch `shell_html`.
The maximum is 100 direct images, excluding nested relative paths and including
all administrator-visible visibility classes. Order is sort_order, filename, id;
id makes ties deterministic. The original row renderer is shared by full pages
and deferred fragments, including cover, visibility, preview and bulk controls.
The empty shell contains the existing toolbar and form rather than a parallel UI.

The inventory observation combines count, newest update, identity sum and
identity-weighted order with the gallery edit revision. It is checked before
and after each batch and sealed again after the final thumbnail wave; changed
observations return 409. This is a drift guard,
not a multi-request database snapshot. Same-second external image-only metadata
writes can share an aggregate observation. The browser additionally rejects
wrong galleries, duplicate IDs, inconsistent totals/revisions and incomplete
end-of-list contracts. When a mutation requests editor replacement, the old task
checks connection and drawer generation before every DOM effect.
No photo response reads or replaces the settings form or its unsaved values.

CSRF state is initialized before the batch request closes its PHP session.
List preparation and File renamer JSON therefore do not retain the exclusive
session lock while settings are saved. Thumbnail requests retain the existing
media authentication, visibility/NSFW preflight and private caching policy;
existing media session-release ownership remains unchanged.

## Resource and interaction bounds

The preload owner admits six actual table thumbnail elements concurrently.
It does not create duplicate Image objects, full-resolution bitmap copies,
Blob arrays or original downloads. Each metadata/resource request has a
20-second deadline; DOM insertion yields after twenty rows. Metadata pages
are sequential and the next page waits for the current page's thumbnails,
keeping pending resource/transport memory bounded independently of gallery size.
The completed DOM necessarily retains the gallery's complete image table.

Preload URLs select the existing protected thumbnail route with 300px size,
the existing browser format policy, source cache version and `existing_only=1`.
That opt-in resolver accepts only the exact existing size/format, uses the
existing source-ownership guard and reads only the derivative's own format/dimensions, and never creates, deletes, repairs
or records derivatives. Missing files become explicit failures. Original-file geometry/EXIF reads are excluded. The ordinary
thumbnail resolver and visual-preview fallback remain supported by default.
900px previews are merely retained as on-demand button URLs.

Images is aria-disabled, removed from keyboard traversal and rejected by the
shared activation function during preparation. Direct Images query intent is
remembered and selected only after readiness. Other tab clicks do not change
the public drawer URL. The three-pixel progress track first exposes an honest
indeterminate state, then counts settled resources. It cannot show 100 before
both end-of-list and resource settlement. Load/decode errors and timeout failures
count separately, receive stable placeholders, and leave a retry action.
After all requested resources settle, Images becomes usable even if some
thumbnails failed. Batch errors keep Images unavailable but never disable
settings. An empty initial gallery becomes ready without an image-list request.

Retry discards only Images content, fetches current metadata and reuses valid
browser thumbnail cache. Closing, switching gallery or replacing the editor
aborts its task through the existing drawer owner and fragment connection guard.
Photo-owned successful local mutation events also restart the preload when
canonical draft protection keeps the settings fragment mounted.
Canonical `gallery.cover` completions retain the prepared table: the star owner
already applies their authoritative state in place. They preserve row/thumbnail
nodes, bulk selection and Images readiness while the shared coordinator refreshes
public fragments. Other photo metadata/inventory mutations still restart preload.
Existing image-specific initialization runs on the completed table; the
side-panel owner marks its bulk form for the existing delegated AJAX pipeline.

## Evidence and acceptance

Hosted BASIC checks syntax and declaration documentation only. It does not run
these regressions, provide performance evidence or qualify browser/database
behavior. The central PHP tree contains `admin_gallery_images_deferred_test.php`;
the existing Node delegation owner unconditionally awaits its Images queue
scenarios without changing the protected BASIC registry.

The isolated PHP fixture exercises 0, 1, 50, 500, 1000 and 3001 direct images,
scope/count, bounded complete canonical rows, withheld thumbnail sources, stale
settings/order observations, malformed paging and authentication precedence.
Its shell/full response byte sizes and timings use the same disposable SQLite
connection and prepared rendering stubs. They measure orchestration/row markup,
not a full PHP installation, network, image decoding, EXIF or hosting latency.
The Node fixture exercises production queue admission, real parallel work at
that boundary, decode/error/timeout settlement, cancellation and the same sizes.
Physical browser heap and server parallelism cannot be inferred from Node mocks.

Record exact hosted candidates and comparative live timings in the issue.
Remaining live acceptance must cover cold/warm cache, representative
WEDOS latency/bandwidth, 0/1/50/500/1000/3000+ images, Access visibility saves
during pending images, image mutations, close/switch/reopen, direct entry,
desktop and narrow/mobile layouts, broken 403/404 resources, peak request
concurrency, main-thread response and physical peak memory. Six workers are
the conservative initial policy; live measurements can justify changing it.
No claim of sub-second hosting performance follows from the implementation.
