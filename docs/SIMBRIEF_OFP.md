# SimBrief OFP document viewer and private page subgalleries

Implementation: https://github.com/klusik/PHP_gallery/issues/103

## Normal workflow

An administrator imports the latest SimBrief flight using the existing gallery editor. Saving the import retains the route/description, raw OFP JSON, manifest, and the **original PDF** when the SimBrief response exposes a trusted PDF file URL. No conversion to images occurs by default.

The rendered gallery description area exposes **View flight plan (OFP)** and **Download OFP PDF** only if a valid local document exists. Both work even when the parent gallery contains no photographs. The link comes from locally persisted attachment data, not an expiring SimBrief download link written into editable Markdown.

## Historical galleries: administrator-guided dispatch and PDF upload

Issue [#104](https://github.com/klusik/PHP_gallery/issues/104) adds a **single-gallery, optional manual workflow** for galleries created before an OFP was saved. It neither scans galleries in bulk nor invokes an automatic SimBrief generation API.

1. Open the existing gallery in Admin and go to the **API** editor tab. With SimBrief enabled, the **Legacy flight plan (OFP)** panel checks the same local PDF resolver used by the public gallery. If a valid PDF already exists, use **View OFP** or **Download PDF**. An optional **Prepare revised OFP in SimBrief** action can reopen the editable review, but no regeneration is automatically requested and the existing document stays intact. Source JSON, images and description remain unchanged.
2. If no valid PDF exists, select **Create historical OFP / Open prefilled SimBrief**; with a valid PDF, explicitly select **Prepare revised OFP in SimBrief** instead. Both open the same local **modal review dialog** first. It identifies the gallery and displays precisely the supported fields that could be sent to SimBrief: departure/destination ICAO, date and UTC departure hour/minute, aircraft ICAO type, airline and flight number, callsign, registration, filed route, flight level, alternate and passengers. Known fields come from that gallery's saved OFP JSON and structured route information. An unambiguous stored gallery date can be offered **as uncertain**, requiring administrator verification. Missing values are blank; titles and EXIF timestamps are never guessed as flight facts.
3. Edit, clear, confirm or cancel the transient fields. **Cancel** and opening/editing the dialog make no remote requests and do not save gallery data. Only **Open SimBrief Dispatch** assembles safely encoded parameters for the fixed official URL https://dispatch.simbrief.com/options/custom and opens it in a protected new tab. SimBrief may require login, and the administrator must independently review options and select **Generate** there. A link requires no API key or OAuth. Dates are encoded as SimBrief's DDMMMYY (for example 03JUN26), but **acceptance of old departure dates on SimBrief itself remains unverified**. A historical date might be rejected or normalized there.
4. Download the newly generated PDF from SimBrief. Return to the same gallery and select **Upload OFP PDF**. The independent upload form accepts one PDF of up to 25 MiB and requires an explicit provenance selection: **newly generated retrospective OFP** or **manually supplied document**. This selector only records the origin of the **file being uploaded**; changing it never generates, downloads or fetches a PDF. The successful attachment is stored in the selected source gallery and indexed in the existing canonical OFP manifest. The same access-controlled viewer/download routes then serve it.
5. An existing PDF is **never silently overwritten**, including while editing dispatch fields and generating a new OFP on SimBrief. After downloading the new PDF, a separate collapsed **Replace existing OFP PDF** upload action requires a newly selected local PDF and check-box confirmation. PDF storage uses per-gallery locking, content checks (signature, EOF, MIME when fileinfo is present, maximum size), bounded staging, symlink/path restrictions and rollback on write failure. No photo pipeline, original raw OFP JSON, route data, gallery date, description or publication status is modified.

The native departure-date input has a dedicated full grid row, avoiding browser date-widget overflow on narrow and mobile layouts. When opened from the **right-side Admin editor drawer**, the review dialog stays within that drawer's focus/isolation owner but is rendered as a native top-layer modal, outside the drawer's clipped scrolling content. It remains editable; `Tab` moves between fields, and `Escape` or **Cancel / Back** dismisses only the review and returns to the still-open editor. From the standalone editor, the same dialog opens directly in the page. Neither dismissal calls SimBrief or changes gallery data.

**Provenance is essential:** an imported original flight-day OFP is an archival document, while a retrospective dispatch is a **new approximation**. Present-day weather, AIRAC, NOTAMs, routes, payload and fuel do not reconstruct conditions on the original flight day. The manifest preserves the provenance and attachment timestamp for manually supplied attachments; original imports without a provenance field are treated as the existing original SimBrief import.

No bulk fetch, refresh timer, periodic polling, automatic generation, developer key or Navigraph OAuth is involved. Recovering exact past documents from a saved link or importing a later **latest** OFP automatically is deliberately not part of this version.

The cruise flight-level input uses hundreds of feet (for example `350`). The redirect converts it to the documented `fl=FL350` representation; a bare `fl=350` is not the documented altitude format. Blank flight levels are omitted. See the [official Dispatch Redirect Guide](https://forum.navigraph.com/t/dispatch-redirect-guide/5299).

Provider check on 2026-10-08: an unsigned browser opened the official redirect with synthetic `LKPR`, `ESSA`, `date=03JUN26` and UTC `07:20` inputs and reached the SimBrief sign-in screen. This confirms the login gate, **not acceptance or preservation of the historical date**. Complete that check in an authenticated session: inspect the prefilled date before Generate, record any rejection or normalization, and retain the source gallery's historical date unchanged. No plan was generated during this check.

## Storage

Each source gallery folder may contain:

- simbrief-ofp.json: raw OFP data
- simbrief-ofp-manifest.json: OFP metadata, route and attachment index
- simbrief-ofp.pdf: original downloaded PDF **or** explicitly attached retrospective/manual PDF (see manifest provenance)
- ofp-pages/: optional *physical child gallery* of generated JPEG images

The local PDF is retained as the canonical original. Reimporting flight data does not automatically regenerate an existing OFP page gallery.

## Authorization and PDF serving

The canonical, gallery-scoped PDF delivery endpoint is:

- View: `index.php?page=gallery_ofp_pdf&id=GALLERY_ID`
- Download: `index.php?page=gallery_ofp_pdf&id=GALLERY_ID&download=1`

These links are **root-relative and same-origin**. They use the actual front-controller mount path (for example, `/galerie/index.php` on a subdirectory installation), not an absolute configured `base_url` that could send an anonymous PDF.js fetch to another host and cookie scope. URL rewriting is never required. Previously emitted `page=media&id=GALLERY_ID&ofp=1` links continue to work, but new HTML uses the unambiguous dedicated route so gallery IDs cannot be mistaken for image IDs when parameters are normalized.

An anonymous visitor may view or download the **saved original PDF of a public or unpublished (unlisted) gallery** using its direct gallery URL without logging in. Unpublished galleries are absent from normal listings, but they are **not private**; their PDF has the same access rights as the gallery page. Password, share-token and NSFW access grants continue to apply, including when inherited from a parent. A private source or generated private `ofp-pages/` child stays inaccessible without an ordinary valid gallery grant. A verified administrator may inspect private source OFPs unless anonymous preview is enabled. PDF authorization never gains implicit administrator privileges from the page, a cached response or a visitor session.

The PHP media controller checks the gallery policy **before** opening the validated local attachment. The attachment service validates a known local filename, manifest marker, symlink/path boundaries, 25 MiB size limit and PDF signature. Successful GET/HEAD replies use `application/pdf`, fixed inline/attachment disposition, no-sniff and `private, no-store`; HEAD returns metadata only. Forbidden, absent and invalid PDFs intentionally share an opaque, uncached **text/plain 404** response instead of rendering the normal HTML 404 page. This avoids exposing whether a protected gallery/PDF exists and prevents PDF.js from consuming an unrelated HTML document. File paths, tokens and the remote SimBrief URL are never serialized into the response.

The galleries/.htaccess policy denies direct static PDF access. **Nginx/other servers ignoring Apache .htaccess require equivalent URL access restrictions for gallery PDF files.** Do not expose the gallery source folder without server-level access rules.

Import downloads only from HTTPS SimBrief URLs, rejects redirects, caps the response body to 25 MiB, enforces a 30-second timeout, and stages the completed download before replacing a prior file. On reimport failure, the previous imported PDF/snapshot remains intact.

## Document lightbox

The viewer uses locally distributed Mozilla PDF.js, with the original vendor license under public/assets/vendor/pdfjs. PDF.js runs without an external CDN or server-side rasterizer; eval-based PDF.js code generation is disabled.

The overlay shares the visual language of the existing photo lightbox, but owns its own **document-only page sequence**. Navigating from the first/last PDF page never enters the parent's photographs. It has:

- Document page counter and previous/next page navigation
- **Fit whole page** is the initial mode. It computes the smaller width/height scale from the actual scroll-stage client dimensions, canvas padding and clearance, centering the entire page without covering edges with the header or toolbar.
- **Fit width** is an explicit alternative allowing vertical scrolling. **Actual size (100%)** uses PDF.js's 96-CSS-pixel/inch scale. View-mode buttons expose the active automatic mode with `aria-pressed`.
- Zoom in/out reports percentages relative to the last fit; **Reset zoom (100%)** restores that fit. Ordinary mouse-wheel and two-finger trackpad gestures **scroll the PDF in both axes**; Ctrl+wheel or a trackpad pinch **zooms only the PDF** at the cursor, with Safari GestureEvent fallback. On touchscreens, one finger scrolls and two fingers pinch; releasing one finger during a pinch continues panning. All gestures remain independent of gallery-photo navigation.
- Automatic fit modes recompute for every PDF page's dimensions/orientation, stage resize, device rotation, and fullscreen transitions. Manual zoom retains its chosen CSS scale and scroll position across ordinary stage resizing until an explicit fit or reset.
- Responsive layout, lazy per-page rendering, bounded 16-megapixel high-DPI canvas
- Cleanup of page/worker resources on navigation and close
- Original PDF download link and browser-native PDF fallback

The viewer's header, toolbar, zoom percentage and fit-mode labels use an isolated, fixed high-contrast dark palette. The gallery's theme accents and text colors do not override the PDF HUD, including native/fullscreen fallback, focus, hover, active and disabled controls. The header and toolbar do not scale with the PDF.

The normal photo lightbox, map and slideshow state are not modified by PDF page navigation.

## Optional server-side PDF page conversion

The public description displays compact **View** and **Download PDF** actions together. For administrators, the private-subgallery creation button appears separately below them, beside a localized, keyboard-accessible question-mark control. The help opens on hover, keyboard focus or tap, and closes on click-away or Escape without consuming a permanent description line.

Only an authenticated administrator can manually request **Create private OFP subgallery** through a CSRF-protected action. Nothing is converted automatically. Hosts without Imagick plus working PDF and JPEG delegates show a capability notice and retain normal PDF viewing.

Conversion uses the locally saved PDF, not SimBrief. It stages JPEGs in a private random cache directory, creates a physical child at ofp-pages via existing gallery creation APIs, transfers ordered images, and indexes them with the existing image scanner. It records a generated-pages marker containing the parent gallery ID, source PDF SHA-256 and page count.

**The generated child is private, not unpublished.** PHP Gallery's unpublished state is still directly accessible by URL. A genuinely private child ensures that viewers cannot access the OFP page images before the administrator publishes it manually.

Conversion resource limits:

| Resource | Limit |
| --- | --- |
| Original PDF | 25 MiB |
| Converted pages | 40 |
| Resolution | 120 DPI |
| Pixels per page | 9 million |
| Total JPEG output | 64 MiB |
| Working-time budget | 60 seconds |

An existing correctly marked OFP child is returned unchanged on repeat conversion. Existing manual edits and publication changes are never overwritten. A conflicting folder/gallery without the expected ownership marker is never reused. Failures clean staged data and attempt to roll back newly created private gallery entries using the existing deletion service.

To deliberately regenerate, inspect/export and remove the old generated child through the standard admin workflow before launching another conversion. The source PDF remains available in the parent gallery.

## Regression and maintenance

- tests/simbrief_ofp_lightbox_browser_test.mjs verifies stage-native scrolling versus Ctrl-only PDF zoom, theme-independent HUD contrast, help visibility, portrait A4 and mixed-orientation whole-page geometry on a landscape stage, fit-width and actual-size modes, center/bounds, mode indication, manual resize persistence, simulated rotation/fullscreen changes, translations, download isolation and resource cleanup.
- tests/simbrief_ofp_document_test.php covers manifest validation, PDF signature and symlink refusal, generated-gallery ownership checks, no-op idempotency, **manual upload/explicit replacement, provenance, local historical prefill and gallery isolation**, and the separate viewer contract.
- tests/simbrief_ofp_public_http_test.php starts a disposable real PHP HTTP server with isolated gallery fixtures. It checks clean-cookie anonymous GET/HEAD and downloads for public, unpublished, unlisted and legacy draft sources; old links; private/NSFW/password/share gates; invalid/missing PDFs; admin preview isolation; root/subdirectory paths and non-rewrite URLs. tests/public_media_authorization_contract_test.php exercises the same PDF authorization helper against the actual gallery-access policy.
- Existing gallery import/export, deletion, backup and thumbnail workflows should continue treating the OFP pages as an ordinary physical child gallery.
- New production assets and PHP services must appear in the checked-in production inventory and core manifest.
- A complete hosted browser/integration test must also exercise a real multipage SimBrief PDF, restricted galleries, the zoom UI, and optional Imagick on a capable host.
