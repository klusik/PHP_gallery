# General gallery PDF documents

The gallery presents documents in a compact table with title, original byte size,
actual PDF page count and text-sized icons. The title and eye icon open the same
protected viewer; the download icon retains the original filename. PDF.js reads
page-count metadata sequentially without rasterizing pages. Unknown or unreadable
counts remain an em dash. When a description exists, only that description and
the PDF table share a desktop row (text left, documents right). With no description
the table is left-aligned; narrow screens stack the two blocks. Tags, breadcrumbs,
dates and other gallery controls retain their existing placement.
The text/document row spans the panel's inner width below the title/actions row.
The document table aligns against its right padding, all action icons remain on
one line, and long titles use an ellipsis with the complete title in a tooltip.
The normal collapsed state has one compact row per PDF; expanded conversion
controls remain available below the selected document. Very narrow screens allow
horizontal scrolling within the document area rather than stacking action icons.
Expanded controls use a fixed-layout table inside a bounded document column, so
their descriptions and selects cannot increase the column's intrinsic width and
compress or overlap the gallery description.

When a child target is missing, changed away from private before completion, or
its reserved path belongs to unrelated data, the browser offers explicit
confirmation to start a fresh private result. This creates a separate generation
with a new conversion identity, folder and session token; prior markers, files,
receipts and galleries remain intact. Legacy markers without generation metadata
remain generation zero. Retries select the latest generation for the exact source
and render key. Only recognized child-target failures offer this recovery; source,
storage and unrelated failures never silently restart. Confirmed restart retires
only obsolete browser-side packages, never previously acknowledged server pages.

Administrators can expand PNG conversion controls, hide/show a document, or
confirm deletion directly from the table. All enhanced actions update in place.
Hidden documents remain visible to administrators but are denied to other visitors
through both the table and protected viewer/download URLs, including share access
and anonymous administrator preview. Manual records persist `hidden` in the
gallery-local index; the same index envelope stores `ofp_hidden` for the legacy
SimBrief attachment. Older indexes omit these fields and remain visible by default.
Manual replacement preserves visibility, and index mutations preserve the OFP flag.
An invalid visibility index is treated as hidden rather than exposing an OFP.
Confirmed deletion removes only the original PDF and its PDF metadata; generated
images and galleries remain. OFP deletion preserves its flight-plan JSON and
non-PDF manifest fields. A failed deletion rollback retains the denied backup
for recovery if it cannot restore the original path.

General PDF documents belong to a physical gallery independently of SimBrief.
The gallery editor stores each original PDF and its metadata in a lightweight
gallery-local index. The index records a stable opaque document ID, byte size,
SHA-256 digest, original filename, display title, provenance, and creation/update
timestamps. The original bytes remain available for viewing and download; page
conversion creates PNGs and never replaces the source PDF.

Public document presentation loads through its reviewed runtime module only when
an OFP or manual document index exists. The publication guard loads for reserved
PDF child paths; ordinary photo galleries do not load the conversion lifecycle.
Isolated CLI consumers retain a direct include fallback for that access guard.
PDF delivery, presentation and its publication guard share the existing attachment
runtime owner. Historical `media&ofp=1` URLs load it before dispatch; ordinary photo
requests do not load document storage. Authenticated conversion controls reuse the
existing Admin uploads owner rather than adding a parallel upload module.

A deleted destination is reported separately from a missing original PDF.
Submitting Convert again with unchanged inputs recreates an empty abandoned child only
when its gallery row and physical folder are absent, no pages or image IDs were
acknowledged, no registered images remain, and no successful first-batch receipt
exists. The old marker is archived beside the denied JSON sidecars and the new
session receives a fresh token. Conversions with progress are preserved for review.

The existing-gallery **Upload photos** chooser and drop queue also accept PDF
originals. PDFs show a document tile rather than an unsupported-image warning.
The form offers a private child destination or append mode, plus the same DPI
choices. Mixed selections upload ordinary photos first, then attach and convert
PDFs sequentially; appended PNG pages therefore follow the final uploaded photo.
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
Responses use the originating PDF form's language without changing language preferences.

The existing protected, same-origin PDF delivery route accepts the gallery ID
and document ID. Authorization follows the selected gallery's normal visitor
policy, including visibility, password, share-token, and NSFW requirements.
Unauthorized, missing, and invalid documents use the opaque media response.
Legacy SimBrief OFP URLs continue resolving the original OFP through the OFP
manifest and are independent from the general-document index.

An administrator can render a locally stored document in the browser with the
vendored PDF.js runtime. The browser renders pages sequentially to PNG and sends
them through the existing bounded, resumable ZIP upload pipeline. That pipeline
owns upload progress, batch acknowledgments, retry/resume state, and cancellation.
The configured resolution applies to new conversions: 150 DPI by default, with
96, 150, 200, 300, 600, 1200, and 2400 DPI choices. There is no product
page-count cap. A page above the conservative 16,384-pixel edge or 32-million-pixel
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
pages, so a lost acknowledgment does not cause stored pages to be uploaded again.
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
