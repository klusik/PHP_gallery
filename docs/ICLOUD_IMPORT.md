<!--
Project: PHP Gallery
Repository: https://github.com/klusik/PHP_gallery
File: docs/ICLOUD_IMPORT.md
Module Type: Upload Contract
Purpose: Define assisted iCloud acquisition and bounded existing ZIP uploader reuse.
Responsibilities:
  - Separate verified manual acquisition from unverified automatic Apple exports.
Author: Rudolf Klusal
-->

# Assisted iCloud photo import

`admin-icloud-source.js` recognizes HTTPS Photos Links at
`share.icloud.com/photos/{token}` (and `www.icloud.com/photos/#{token}`), and
public Shared Albums at `photos.icloud.com/shared/album/{token}/` (including
the observed Apple redirect `www.icloud.com/photos/#/sc,{token}/`). These are
distinct products. Drive/library URLs, credentials, ports, queries, encoded
delimiters, backslashes and lookalike hosts are refused. Recognition checks
syntax only, never live availability, expiry, authentication or CORS.

The collapsed source controls are in existing upload forms. The unnamed URL
input is cleared after recognition. A form-owned WeakMap retains the bearer URL
for explicit opening with `noopener,noreferrer`, never FormData, server requests,
drafts, diagnostics or persistent storage. Apple credentials are not collected.
The source adapter creates no route, proxy, ingestion service or new permission.

## Feasibility decision (2026-10-13)

Anonymous inspection of the owner-provided Shared Album in the Codex internal
browser showed Download album -> Download Zip, without a Most Compatible control
in that dialog. One downloaded ZIP contained 11 JPEG entries totaling 28,125,579
uncompressed bytes. The page displayed expiration on 12 November. No photo bytes,
filenames, EXIF/GPS or share token are committed or used by CI. This is one album
observation, not a general export guarantee.

[Apple download instructions](https://support.apple.com/en-nz/111762) describe
Most Compatible in the Photos interface; they do not establish a recipient API
for both products. [Apple Shared Album instructions](https://support.apple.com/en-gb/guide/icloud/mme985ae44d8/icloud)
distinguish temporary albums from owner-controlled availability. A Photos Link
fixture, recipient format controls, metadata/edit fidelity, documented export
endpoint, redirect/CDN/CORS and Range identity remain unverified.

**LIMITED GO:** open Apple, choose Most Compatible when actually offered, download
ZIP(s) or individual compatible images, then select/drop them into the existing
picker. Expired/revoked/sign-in-only links require owner resolution on Apple.
Gallery does not silently check or download them and cannot capture another
origin's OS download with OPFS. No `no-cors`, internal endpoint scraping, PHP
downloader or OPFS pseudo-staging is introduced. No Gallery download percentage
is displayed. Live albums are never deterministic test dependencies.

## Resource and integration contract

`expandBrowserUploadArchives()` and the existing image worker read directory and
entry slices, never the complete selected File into an ArrayBuffer. Stored and
Deflate work; ZIP64, multi-disk, malformed bounds and missing browser inflation
fail explicitly. Inflation stops at the admitted declared size, followed by CRC
and supported image signature validation. Relative safe paths are required;
traversal, absolute paths, hidden entries and symlinks are skipped. Duplicate
archive paths are counted; distinct folder paths with the same basename remain
separate occurrences under the established server filename policy.

The 10,000-entry, effective server per-image limit, ratio guard and at-most-2-GiB
expanded-data ceiling remain. Directory allocation is limited to 4 MiB. Retained
originals across the whole selection containing ZIPs, including ordinary images,
are limited to 64 MiB before server persistence. This bounds the existing
File-array seam; it is not a measurement of total browser heap. Decoding,
thumbnails and prepared ZIPs consume additional memory. Larger exports must be
split. Unlimited large-image/ZIP64 streaming remains open; future iteration must
extend this worker and batcher rather than build another uploader.

Skipped RAW/HEIC/HEIF, video/Live Photo motion, sidecars/unsafe paths, duplicates,
encoding, size/ratio and integrity failures have counted localized reasons.
No renaming-as-conversion or RAW/metadata-fidelity promise is made. Drawer order
retains ZIP position/directory order; standalone uploads retain natural filename
ordering. Native decoding and existing PHP signature/CSRF/access checks remain.

Prepared items and small upload ZIPs use the existing IndexedDB batcher; writes
are accepted at transaction commit, including abort/quota handling. Preparation
failure cleans owned temporary packages before upload. The PDF stream keeps its
page/token/resume semantics; shared cancellation wording and transaction durability
are strengthened. Effective PHP request ceilings still bound each batch.

## Lifecycle and evidence

`idle -> recognized (syntax only) -> Apple/manual download -> file selection ->
explicit submit -> ZIP inspection -> preparation -> batches -> canonical completion`,
with failure/cancellation. Cancel stops ZIP/preparation workers, backoff and XHR;
admitted temporary writes settle before cleanup. Accepted server photos remain.
Uncertain results retain the original form and existing session/batch replay
identities; Retry submits the same intent. Acknowledged packages use existing
cleanup. Canonical mutations/contexts still pass to the existing completion owner
and the drawer stays open without URL navigation.

New assisted targets require Private/Unpublished (existing legacy Draft vocabulary
is accepted); creation follows preparation and partial imports stay non-public.
Existing visibility stays authoritative and must be reviewed by the administrator.
Closing the tab loses in-page retry ownership; background import, restart and
age-based orphan cleanup are not promised. OS downloads remain until user deletion.

Physical heap/storage measurements for 100 MiB/1 GiB/>2 GiB archives, live Photos
Link export and complete desktop/mobile browser qualification remain open.
Synthetic fixtures prove deterministic boundaries, not these platform measurements.
EN/CS/DE/SV catalogs and all four TeX sources describe the implemented scope;
generated PDFs remain release-owned.
