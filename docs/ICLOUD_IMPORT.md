<!--
Project: PHP Gallery
Repository: https://github.com/klusik/PHP_gallery
File: docs/ICLOUD_IMPORT.md
Module Type: Upload Contract
Purpose: Define direct public iCloud acquisition, disk-backed ZIP iteration and existing upload replay ownership.
Responsibilities:
  - Separate observed anonymous Apple protocol support from its undocumented compatibility boundary.
  - Document source privacy, resource limits, cleanup, retries and the explicit manual alternative.
Author: Rudolf Klusal
-->

# Import public iCloud photos

Paste a public Photos Link (`share.icloud.com/photos/{token}` or the supported
`www.icloud.com/photos/#{token}` spelling) or Shared Album
(`photos.icloud.com/shared/album/{token}/`, including Apple's `#/sc,{token}/`
redirect spelling). Select the destination and choose **Download and import from
iCloud** beside the pasted link. This one action recognizes the link and submits
the existing form; the footer shows the same action for an automatic source.
Browser preparation stays enabled. Automatic import needs no ZIP file selection.
Recognition checks syntax; submission checks public availability.
**Open iCloud for manual export** explicitly selects the existing manual
alternative under **Manual export and details**. Clear the source for ordinary
local uploads; the footer and native file requirement return to their original
state. Simultaneous automatic
source and local-file selection is refused; native file upload remains
available.

## Acquisition and privacy

`admin-icloud-download.js` uses the observed Photos CloudKit `records/resolve`,
validates the returned Apple partition/database/zone and anonymous public
access,
then pages the product-specific asset/master query. Shared Albums use
added-date;
Photos Links use asset-date. Sign-in-only/foreign/malformed sources, repeated
continuation markers, empty sources and over-limit inputs fail closed. Requests
use `text/plain`, CORS, `credentials: omit`, no referrer, no cache and refused
redirects. No Apple credentials or cookies are acquired.

ZIP preparation uses Apple's observed Most Compatible item-types (JPEG/PNG/GIF
and compatible video representations) and H.264 codecs, including the resolved
share name. Actual unsupported media still receive counted outcomes. This is an
**undocumented client protocol**, not a stable official export API. Changes
yield
localized retry/manual-export guidance. Source URLs, anonymous tokens, signed
download URLs and metadata remain browser-local. Gallery receives only its
normal
prepared media batches, CSRF, target and replay fields.

Owner-album research on 2026-10-13 reproduced 11 asset/master pairs and a
compatible
ZIP with CORS. A real internal-browser run downloaded 28,131,903 bytes to OPFS,
incrementally verified 11 JPEGs totaling 28,125,579 bytes and removed the
source;
it performed no Gallery upload. Range returned 400 and is not used. No real
media,
filenames, EXIF/GPS or bearer tokens enter source/CI fixtures. A live ordinary
Photos Link was unavailable; its separate path has synthetic protocol coverage
and is not called live-qualified by the Shared Album observation.

## Disk staging and bounds

Automatic acquisition requires a secure context, OPFS and Web Locks. The
response
stream is read incrementally; at-most-1-MiB writes are awaited before more data
is
consumed. There is no whole-archive `Response.blob()`/`Response.arrayBuffer()`.
Known length is compared with free quota and the 4-GiB-minus-one source bound.
Unknown length shows received bytes without a fabricated percentage and retains
the running cap. Quota failure, abort, idle timeout and early end close handles
and remove the incomplete owned file. Metadata has a separate 4-MiB response
cap,
200-result pages, 100-page guard and 10,000 selected-asset ceiling.

The dedicated `php-gallery-icloud` OPFS directory uses random timestamped
filenames
and a per-file exclusive Web Lock throughout staging and in-page retries. A
later
acquisition removes only matching files older than one day after acquiring their
lock; active work in another document cannot be removed. Success removes the
source
immediately. Closing a document releases its lock, without promising synchronous
cleanup; the next acquisition reclaims aged abandoned files. No background or
cross-session upload/recovery and no persisted bearer URL is introduced.

## ZIP and existing upload ownership

`browser-upload-archive-stream.js` opens the existing `browser-image-worker.js`
ZIP owner once, then pulls one admitted image at a time. Only the archive
reference
and metadata remain between reads. Stored/Deflate, safe paths,
symlink/encryption/
compression refusal, CRC and real image signatures share the ordinary ZIP
policy.
The end record admits only an all-zero suffix of at most 4 KiB, covering Apple's
observed 205-byte padding; nonzero trailers remain invalid. Tail reads are
bounded
to 69,653 bytes, directory allocation to 4 MiB, entries to 10,000, one image to
64 MiB and the effective PHP limit, and admitted expanded data to 2 GiB. ZIP64
and
multi-disk structures still fail explicitly. These are admission bounds, not a
measurement of total heap/decoder memory.

Manual ZIP selections and automatic imports both retain one original/worker
item and bounded packages, not an all-images File array. Manual archives can
exceed 64 MiB in aggregate; the shared per-image, directory, entry-count and
expanded-data bounds remain enforced. Manual selection streams also retain
source descriptors and the existing exact batch/cursor across lost responses.
Integrity skips reduce the source count; automatic imports preserve remaining
ZIP directory order. RAW/HEIC/HEIF, video/Live Photo motion and sidecars have localized
counted skips, with no rename-as-conversion or unrequested local lossy
conversion.

`uploadBrowserFileStream(..., {sourceKind: 'images'})` reuses the existing image
worker, IndexedDB batcher, ZIP packager, same-origin prepared endpoint, session/
batch acknowledgments and canonical aggregate. A ready small batch is uploaded
before more images are requested. A new target is created after the first valid
prepared batch and must be Private/Unpublished. Existing visibility remains
authoritative, including partial results. PDF is the default stream kind and
retains its exact PNG-byte, conversion-token, page/DPI and resume contracts. No
server route, downloader, proxy, schema, capability or second ingestion engine
is added.

## Cancel, Retry and completion

The lifecycle is recognition -> availability -> compatible export ->
network-to-disk
download -> ZIP inspection -> incremental image preparation/batches -> canonical
completion. Progress uses observed phase/byte/item counts.
The injected Admin language scope owns source, archive and image-stream
messages,
including when the upload panel is opened on a public page in another language.
Cancel stops fetches and
disposable workers; admitted temporary writes settle before cleanup. Before
server
work, safe failure abandons packages and the source. After server work, the
immutable
source/cursor and exact pending ZIP/session are retained for Retry in the
original
form. Unacknowledged batches are reconciled before reading the next image.
Source
and target intent remain locked; already accepted photos remain.

Panel actions complete in place with unchanged URL and the existing canonical
mutation completion coordinator. Physical large-source heap/throughput, Safari/
Firefox/mobile, live Photos Links and authenticated hosted acceptance retain
their
separately recorded evidence boundaries. EN/CS/DE/SV and all four TeX sources
describe these behaviors; generated PDFs remain release-owned.
