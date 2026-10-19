# General gallery PDF documents

**Admin → Features → Gallery PDF documents** controls original PDF uploads,
document tables/viewers/downloads and browser PDF conversion independently of
music. New installations and upgrades without a preference default ON; explicit
OFF persists. OFF rejects stale editor forms, historical OFP uploads/conversion
actions and prepared PDF-page batches before target work. Dedicated and legacy
delivery links are denied. Existing originals, indexes, conversion markers and
preferences remain stored. Existing indexed page photographs retain their normal
photo behavior, and incomplete-child publication guards remain enforced.
SimBrief descriptions/routes can still refresh with PDF OFF; no PDF is fetched,
and an old original's manifest reference remains available on re-enable.
When both PDF and music are OFF, the shared editor media tab and its empty panel
are omitted. An old selected-tab URL falls back to Identity.

In **Admin → Edit gallery**, the **Documents / PDFs** tab owns manual PDF
upload, the attached-document table, browser PDF-to-PNG conversion, and any
historical SimBrief OFP PDF/dispatch panel. These forms are independent of the
shared Identity/Access/Display/Media settings form. The **API** tab contains
only upload API/automation configuration and its existing advanced tools.
After a non-JavaScript PDF or OFP upload, the editor returns to Documents /
PDFs.

The gallery presents documents in a compact table with title, original byte
size,
actual PDF page count and text-sized icons. The title and eye icon open the same
protected viewer; the download icon retains the original filename. PDF.js reads
page-count metadata sequentially without rasterizing pages. Unknown or
unreadable
counts remain an em dash. A translucent summary stays visible with the
authorized
file count, total original bytes and combined page count, including the OFP.
The public and editor surfaces share one exact-version metadata queue; no PDF
pages are rasterized for these totals. Pending metadata shows an ellipsis; if
any
document is unreadable, the complete total stays unknown instead of omitting it.
PDF and music cards wrap beside each other when the actual gallery width
permits,
then below each other and below the description as space shrinks. Resizing a
window or opening a side panel changes this layout without JavaScript. Without a
description they stay left-aligned. Clicking a launcher reveals a small overlay;
the list body has a 12rem maximum height and scrolls independently. Its count
reflects authorized documents, and the panel fits the visual viewport. Tags,
breadcrumbs,
dates and other gallery controls retain their existing placement.
The text/document row spans the panel's inner width below the title/actions row.
The document table aligns against its right padding, all action icons remain on
one line, and long titles use an ellipsis with the complete title in a tooltip.
The normal collapsed state shows only the launcher and document count. The open
list has one compact row per PDF; expanded conversion controls remain below the
selected document inside that scroll area. Very narrow screens allow
horizontal scrolling within the document area rather than stacking action icons.
Expanded controls use a fixed-layout table inside a bounded document column, so
their descriptions and selects cannot increase the column's intrinsic width and
compress or overlap the gallery description. Escape, the close icon, the
launcher
or an outside click dismisses the overlay. Native details retains the bounded
inline list without JavaScript/Popover API; reduced motion disables animation.
Forms stay inside their original gallery ownership. In-place card replacement
retains the open applet and list scroll; deleting the last PDF preserves any
music applet. See `docs/GALLERY_MUSIC.md` for the shared disclosure owner.

When a child target is missing, changed away from private before completion, or
its reserved path belongs to unrelated data, the browser offers explicit
confirmation to start a fresh private result. This creates a separate generation
with a new conversion identity, folder and session token; prior markers, files,
receipts and galleries remain intact. Legacy markers without generation metadata
remain generation zero. Retries select the latest generation for the exact
source
and render key. Only recognized child-target failures offer this recovery;
source,
storage and unrelated failures never silently restart. Confirmed restart retires
only obsolete browser-side packages, never previously acknowledged server pages.

Administrators can expand PNG conversion controls, hide/show a document, or
confirm deletion directly from the table. All enhanced actions update in place.
Hidden documents remain visible to administrators but are denied to other
visitors
through both the table and protected viewer/download URLs, including share
access
and anonymous administrator preview. Manual records persist `hidden` in the
gallery-local index; the same index envelope stores `ofp_hidden` for the legacy
SimBrief attachment. Older indexes omit these fields and remain visible by
default.
Manual replacement preserves visibility, and index mutations preserve the OFP
flag.
An invalid visibility index is treated as hidden rather than exposing an OFP.
Confirmed deletion removes only the original PDF and its PDF metadata; generated
images and galleries remain. OFP deletion preserves its flight-plan JSON and
non-PDF manifest fields. A failed deletion rollback retains the denied backup
for recovery if it cannot restore the original path.

General PDF documents belong to a physical gallery independently of SimBrief.
The gallery editor stores each original PDF and its metadata in a lightweight
gallery-local index. The index records a stable opaque document ID, byte size,
SHA-256 digest, original filename, display title, provenance, and
creation/update
timestamps. The original bytes remain available for viewing and download; page
conversion creates PNGs and never replaces the source PDF.

Public document presentation loads through its reviewed runtime module only when
an OFP or manual document index exists. The publication guard loads for reserved
PDF child paths; ordinary photo galleries do not load the conversion lifecycle.
Isolated CLI consumers retain a direct include fallback for that access guard.
PDF delivery, presentation and its publication guard share the existing
attachment
runtime owner. Historical `media&ofp=1` URLs load it before dispatch; ordinary
photo
requests do not load document storage. Authenticated conversion controls reuse
the
existing Admin uploads owner rather than adding a parallel upload module.

A deleted destination is reported separately from a missing original PDF.
Submitting Convert again with unchanged inputs recreates an empty abandoned
child only
when its gallery row and physical folder are absent, no pages or image IDs were
acknowledged, no registered images remain, and no successful first-batch receipt
exists. The old marker is archived beside the denied JSON sidecars and the new
session receives a fresh token. Conversions with progress are preserved for
review.

The existing-gallery **Upload photos** chooser and drop queue also accept PDF
originals. PDFs show a document tile rather than an unsupported-image warning.
By default, selecting a PDF in either picker only attaches the original
document:
it does not start rendering, create a child gallery or append any page images.
The collapsed **Advanced** disclosure contains an unchecked **Also convert PDF
pages to PNG** option, private child/append destinations and DPI choices.
Opening Advanced or changing a destination alone does not enable conversion.
Mixed selections upload ordinary photos first, then attach PDFs sequentially.
Only an explicitly enabled conversion renders pages; appended PNG pages
therefore
follow the final uploaded photo. A retry retains the original conversion choice.
Originals remain gallery documents. The panel stays open through attachment,
page upload and final registration. Acknowledged stages are retained for retry;
an uncertain attachment response requires review rather than a blind duplicate.
Each rendered page has a unique worker item ID, so thumbnail ZIP paths cannot
collide across pages. The server continues rejecting duplicate archive paths.
PDF batch validation uses the shared `Gallery\Core\normalize_relative_path`
helper when resolving original ZIP entries; the PDF service imports that Core
function explicitly rather than looking for a service-local normalizer.

Failures in attachment, begin, finalize and cancel are logged under
`gallery.pdf_operation_failed` with the action and gallery/document coordinates.
This covers lifecycle failures before any `gallery.browser_upload_failed` event
can exist. Diagnostic records exclude transport tokens and document bytes.
Responses use the originating PDF form's language without changing language
preferences.

The existing protected, same-origin PDF delivery route accepts the gallery ID
and document ID. Authorization follows the selected gallery's normal visitor
policy, including visibility, password, share-token, and NSFW requirements.
Unauthorized, missing, and invalid documents use the opaque media response.
Legacy SimBrief OFP URLs continue resolving the original OFP through the OFP
manifest and are independent from the general-document index.

An administrator can render a locally stored document in the browser with the
vendored PDF.js runtime. The browser renders pages sequentially to PNG and sends
them through the existing bounded, resumable ZIP upload pipeline. That pipeline
owns upload progress, batch acknowledgments, retry/resume state, and
cancellation.
The configured resolution applies to new conversions: 150 DPI by default, with
96, 150, 200, 300, 600, 1200, and 2400 DPI choices. There is no product
page-count cap. A page above the conservative 16,384-pixel edge or
32-million-pixel
advisory threshold displays a warning; it does not block rendering or silently
downscale the page. The warning estimates only the canvas RGBA buffer at four
bytes per pixel and does not predict total browser memory use. The browser's
actual canvas allocation limits and the existing upload constraints can still
make rendering or upload fail. Conversion runs only after an explicit
administrator action.

Conversion progress and identity are stored in the source gallery's denied
`.gallery-pdf-conversions/` sidecar for both destination modes. The marker binds
the source gallery/document ID and digest, target gallery ID, resolution,
expected pages, upload acknowledgments and final state. It is not stored in the
child gallery, including when the target is a private child.

The administrator chooses one destination:

- A reserved child gallery created private. Incomplete and cancelled children
  remain private until the conversion is complete and reconciled. Existing
  child content is retained on retries and resume.
- The end of the source gallery's ordinary photo sequence. Newly converted PNGs
  follow all existing photos and preserve their order and bytes. This choice
  leaves existing photos and the gallery's current visibility unchanged.

The original document is retained in both modes. Existing photo ZIP exports
include registered photos only, and the existing gallery-migration transfer
does not enumerate the manual-document sidecar; these paths do not transfer
general PDF originals. A full filesystem backup of the gallery tree must retain
hidden `.gallery-pdf-documents/` and `.gallery-pdf-conversions/` directories to
preserve originals and conversion state; there is no PDF-document-specific
restore/import workflow. Provenance labels distinguish
where a PDF came from; they do not assert that a retrospective plan reproduces
historical weather, route, payload, or fuel conditions. Existing JPG children
remain intact. The former `simbrief_action=convert_pdf_pages` browser request
now returns HTTP 409 and asks the administrator to reload and use the browser
converter. The server-side rasterizer helper remains only for CLI and test
compatibility consumers; no browser action invokes it.

An unresolved browser conversion retains its original document, DPI and target.
Replay receipts bind the exact ZIP manifest, ordered page numbers and PNG byte
hashes. Begin/resume reconciles stored success receipts before requesting more
pages, so a lost acknowledgment does not cause stored pages to be uploaded
again.
A replay with changed grouping or bytes is refused, preserving the target.
Submitting Convert again with the exact same source and render settings can
recover an empty orphaned child marker only when its gallery row, physical
folder, registered pages, image rows, and first-batch receipt are all absent;
the old marker is archived and a new session token is issued. Any changed
source or render settings still requires explicit confirmation.

Bulk and editor visibility changes reject public, unlisted, draft, and
unpublished transitions for an incomplete or cancelled reserved child. The
effective visibility policy also treats stale stored visibility as private.
Append conversions leave the source gallery's visibility unchanged.

## Verification status

The service and browser lifecycle have focused regression fixtures in the
repository. Hosted candidate qualification is authoritative. Real-device browser
qualification and deployment-specific authorization checks remain separate
acceptance steps; documentation or a fixture pass alone does not establish them.

## Admin storage accounting

**Storage statistics → Files → Update all** measures PDF and music originals
alongside indexed photographs, thumbnails and DNG display masters. It shows
separate original counts/bytes and an additive overall media total. The existing
picture-only total and photograph charts keep their prior meanings.

After bounded photo-derivative work, SQL aggregates PDF/music/OFP counts and
bytes
from `gallery_attachment_storage`. Uploads, replacement and deletion update only
their category from committed metadata. Repeat updates do not open attachment
directories, JSON indexes or original payloads. Smart unions never add copies.

The first update after migration imports absent categories from at most two
physical galleries per request. Galleries without images are included.
The attachment owner reads the existing bounded indexes and stats only their
expected opaque-ID originals plus a valid established SimBrief OFP path; it does
not decode or hash complete payloads. Actual confined sizes count even when a
size mismatch generates a warning. Hidden, private, locked and globally disabled
attachments consume storage and are counted. Separate copies count separately;
Smart Gallery unions never add copies. Generated PDF pages are already ordinary
indexed photos and are not counted again as attachments.

These are media totals, not an account quota or a recursive full-disk inventory.
Index/manifest JSON, locks, conversion state, unindexed originals,
staging/backup
files, Trash and unrelated caches are excluded. Missing/unreadable originals,
unsafe symlinks and corrupt indexes contribute explicit partial-accounting
warnings; independent readable categories still count and no files are repaired.
Size accounting is not content-integrity or codec validation.

Ordinary Admin page loads only read cached results. Supported web attachment
mutations update an advisory revision to mark old snapshots and active jobs
stale. A failed optional revision write preserves the successful upload
response;
failed category writes remove the derived row when possible, allowing the next
Update all to import it again. If the database outage also prevents
invalidation,
the safe error log identifies the owner/category needing reconciliation.
Out-of-band filesystem changes require explicit invalidation before Update all.
An operator may remove derived rows for the affected gallery with
`DELETE FROM gallery_attachment_storage WHERE gallery_id = <verified-id>`;
this deletes no original, index, preference or authorization data. The next
Update all imports only the missing categories. Invalidate the cached storage
snapshot through the existing refresh workflow. Migration
`202610190001_gallery_attachment_storage.php` creates the accounting table;
older job v2 state is retained but not resumed as a v3 import. No media rewrite
is involved. Missing/unavailable schema fails the report visibly.

### Update all run diagnostics

Export the newest **`storage.refresh_all.completed`** Admin log entry when
investigating refresh performance. Its non-authorizing `run_id` links `started`,
`resumed`, `progress` and `stage_completed` checkpoints. Completion is emitted
after terminal state is saved, including partial failures; replaying a terminal
request does not emit it again. A long phase logs progress every ten completed
steps. A started run with no completion may have stopped before reaching its
end.

The context includes UTC start/checkpoint times, PHP/CMS/OS versions, configured
PHP limits, batch sizes, per-stage outcomes, phase request counts, accumulated
server work, the longest step and three slowest request positions, PHP request
peak memory, final media counts/bytes and import warnings. Up to 128 per-table
ANALYZE timings identify slow/failing tables; an explicit omitted count bounds
larger installations. Final inspection counts identify schema tables and
migration
and code sources read from the existing inspection result, without rescanning.

`wall_seconds` includes browser/network pauses, cache/lock/log overhead and idle
time between requests. `phase_work_seconds` sums monotonic phase callback
timings;
`other_wall_seconds` is the remaining wall time, not a network-only measurement.
Nested operation timings overlap: do not sum them into a total. `db.*` metrics
count named model/service operations rather than every SQL statement.

Instrumented file calls report attempts, returned false results, thrown
exceptions,
elapsed/max seconds and bytes returned by JSON/OFP-magic reads. Missing files
can
produce expected false results. `fs.derivative.*` distinguishes photograph
derivative probes; attachment counters distinguish index reads, original checks,
path checks and category imports. Counts describe application API calls, not
physical disk reads: PHP/OS caches may satisfy them. Inspection stages have
separate
timers for schema inventory, migration parsing, source discovery/reads, PHP SQL
literal extraction, identifier indexing/matching, cleanup rule/count generation,
legacy findings, report enrichment, thumbnail distribution and report saving.
Source/migration reads also report bytes and calls. Table/column comparison
counts
and SQL-literal counts explain corpus size without revealing names or contents.
Nested timers overlap; they must not be added to their enclosing stage time.
Individual inspection SQL statements and path-builder internals are not fully
timed.
No filename, private path, SQL text, media content, workflow capability or raw
exception message is recorded. Metrics are bounded at 256 numeric keys per step,
with discarded updates counted. Logging never performs extra media reads or
queries for measurement, and failure to publish a diagnostic does not fail work.
Workflows resumed from pre-diagnostics state have `complete_observation=false`;
their timings cover only the observed continuation. Ordinary media requests keep
the numeric observer disabled.

Photo keyset reads bind both the cursor and LIMIT as PDO integers, including
emulated prepares. Required source/batch database errors propagate to the
existing
failed-stage handler instead of becoming an empty result. A batch that ends
before
the frozen expected photo count is reached is also a failure, recorded by
`counts.unexpected_empty_image_batches`. These failures preserve the last
successful
snapshot and allow the later database/inspection stages to continue with an
overall
partial result. A completed file stage must actually account for its expected
photos.

When durable thumbnail metadata is available, the initial source-row read also
identifies the DNG sources needing display-master measurement. Ordinary photos
are accounted for immediately; they do not incur derivative keyset queries or
additional browser requests. A gallery with no DNG and already imported
attachment
totals finishes its file phase in the initial request. Mixed collections page
only
DNG rows, using the existing image batch limit. Without thumbnail metadata the
full bounded filesystem fallback remains supported. Existing in-flight jobs
lacking
the new selector finish through their original full-image cursor. Required
thumbnail
aggregate failures now fail the stage rather than authorizing skipped work with
false zero totals. Diagnostics report `counts.photos_completed_from_database`
and
`counts.derivative_rows_pending`; source/inspection/database-table work still
runs.

Source-reference inspection indexes case-insensitive whole words once per file
and per complete SQL-like literal. It prepares current/historical column
vocabulary
once, then looks up memberships instead of repeatedly scanning every source text
for every table/column. Production/test separation and same-literal SQL evidence
remain unchanged. Non-ASCII/punctuation identifiers retain the legacy regex
path.
Every explicit inspection still reads current sources and fresh database data;
this optimization introduces no persistent analysis cache or invalidation
window.
