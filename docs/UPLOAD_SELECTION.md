# Upload drawer selection and preview safety

## Scope and ownership

The right-hand existing-gallery and create-with-upload drawers collect paste, picker and drop inputs in one document-local queue. The native `images[]` FileList is the submit authority. Stable occurrence IDs support intentional duplicate screenshots, removal and ordering; a preview is never substituted for its original. The standalone full-page picker keeps its established selection behavior. Nothing is sent before the ordinary form submission.

`admin-upload-selection.js` filters clipboard image items and excludes text editors. `admin-upload-queue.js` owns ordered File occurrences. `admin-upload-preview.js` owns drawer DOM, focus and disposal. `admin-upload-thumbnail.js` owns admission and decoding, not upload preparation. `admin-side-panel.js`, `admin-browser-upload.js` and `browser-image-worker.js` remain the existing submission/preparation pipeline; `admin-operation-keys.js` retains exact retry authority and the mutation completion coordinator owns public-context refresh.

## Preview budget

| Resource | Bound | Effect outside the bound |
| --- | --- | --- |
| Encoded source admitted to miniature decoding | 24 MiB | Keep original selected with an icon |
| Raster header inspection | First 256 KiB | Unknown or metadata-heavy input uses an icon |
| Source dimensions | 8192 pixels per edge and 16 * 1024 * 1024 pixels total | No preview decode |
| Real concurrent decoder | One for the document, including cancelled work still inside a codec | Wait without starting another decode |
| Waiting decoder requests | Twelve | Safe icon fallback |
| Retained miniature edge | At most 256 pixels, preserving aspect ratio | Never upscale a smaller image |
| Renderer URLs plus pending requests | Twelve per current form | Lazy viewport admission |

Viewport admission observes the fixed-size visible placeholder, not the initially hidden image. The scheduler assigns a miniature URL only after admission; native image lazy loading is not layered on top of this lifecycle.

Only static PNG/JPEG/WebP headers are admitted for preview decoding. GIF, animated PNG/WebP, HEIC/HEIF, DNG, AVIF, unknown types and malformed or oversized headers may show format placeholders even when an upload path supports the original. Missing bitmap/canvas support has the same fallback. Server upload eligibility is separate. Encoded byte count alone is not used as a proxy for decoded dimensions.

One admitted RGBA source surface accounts for at most 64 MiB; twelve maximum-size miniature surfaces account for at most 3 MiB. Selected File storage, compressed thumbnail bytes, temporary browser codec buffers and browser internals are additional. These are deliberate allocation budgets, not a measured process RSS limit. The registered Chromium fixture reports observed URL/decode maxima and 100-item append duration; manual browser memory measurements remain unclaimed.

## Security, compatibility and hosting boundaries

Clipboard text, HTML and remote URLs are not fetched or rendered. Filenames and warning labels use textContent. Local previews are newly encoded PNG Blobs, with no base64 DOM embedding, original-sized retained canvas or server write. No CSP relaxation is required beyond the existing local image Blob support. Generation tokens and abort signals prevent removed items or replaced drawers from regaining resources.

JPEG orientation follows the browser bitmap decoder for the local miniature. Original bytes and EXIF are unchanged by selection/preview; extraction, stripping, derivative generation and metadata registration remain the established worker/server responsibilities. The preview is not a privacy scrubber for an original containing GPS/EXIF data.

The current picker exposes JPEG/PNG/GIF/WebP and optional server-capability formats. Clipboard extraction accepts optional HEIC/HEIF/DNG only with the existing explicit picker hint. Client warnings are advisory; MIME sniffing, path validation, authentication, CSRF, target ownership, final collision-safe filenames, ingestion and visibility remain server-owned. This batch changes no schema, media route, scanner or writer, and introduces no draft/staging publication path. Direct access protection for any future persisted staging feature therefore still requires its separate security review before ingestion changes.

Every occurrence with the same exact filename receives a repeated-name warning, including intentional duplicate screenshots. The queue keeps all originals and clears that warning when only one occurrence remains. Filenames do not serve as upload identities. Empty files, reported server-size excess, unsupported formats and unavailable previews likewise remain visible; one failed preview cannot remove healthy sources. Disabling the native input directly or through its fieldset prevents both paste and drop from appending files.

Shared-hosting limits remain in `app/services/browser_uploads/settings.php` and the runtime limit registry. The form receives the effective `upload_limit_bytes`; worker concurrency and maximum items per ZIP batch remain administrator-bounded. The ZIP soft packing target is not the preview byte ceiling, and the PHP request ceiling remains hard. Existing ZIP entry/expansion/path checks and bounded batch retry are unchanged. No actual WEDOS capacity or browser-process memory measurement is claimed here.

The displayed selection count and total byte size describe the local queue, not one HTTP request: classic AJAX sends one image per request and prepared upload applies its existing ZIP batch limits. No new total-selection limit is inferred from PHP's multipart limits. A hosting-provided CSP must allow local `blob:` image URLs for miniature display; a blocked miniature keeps its filename/icon and original input rather than weakening that CSP or upload authorization.

## Failure and lifecycle

Remove and Clear affect only unsent local selection. Native reset also clears its FileList and miniatures. Before a drawer close or context switch, the inline warning offers Continue selecting or Discard selection; Escape cancels that warning and restores focus. No temporary server draft or browser-local durable storage is implied.

Once an operation owns the input, queue mutations and reset are blocked until its result is resolved. Lost replies or partial classic/prepared failure retain the exact original File sequence and the existing operation/session identities. Explicit retry reuses those identities and the prepared ZIP bytes rather than blindly creating another upload. The operation guard takes precedence over local discard. Only canonical acknowledgment clears the queue; public refresh remains with the shared completion coordinator.

Removal, clear, reset, viewport eviction, canonical success, page hide and external DOM detachment dispose of miniature URLs. A browser bfcache return may rebuild previews from its retained page state, but an ordinary reload does not preserve unsent Files. Uploads already acknowledged by the server are not undone by losing the local page.

## Delivery boundary

This is the local Admin-drawer milestone. It does not enable pasting into the gallery grid, persisted unpublished batches, Commit pictures or Discard server drafts. Those transitions require the later security/ingestion milestones and the independent review boundary recorded in the parent issue. Synthetic Chromium tests do not replace the OS clipboard and Firefox/Safari manual matrix in `TESTING.md`.
