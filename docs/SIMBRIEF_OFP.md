# SimBrief OFP document viewer and private page subgalleries

Implementation: https://github.com/klusik/PHP_gallery/issues/103

## Normal workflow

An administrator imports the latest SimBrief flight using the existing gallery editor. Saving the import retains the route/description, raw OFP JSON, manifest, and the **original PDF** when the SimBrief response exposes a trusted PDF file URL. No conversion to images occurs by default.

The rendered gallery description area exposes **View flight plan (OFP)** and **Download OFP PDF** only if a valid local document exists. Both work even when the parent gallery contains no photographs. The link comes from locally persisted attachment data, not an expiring SimBrief download link written into editable Markdown.

## Storage

Each source gallery folder may contain:

- simbrief-ofp.json: raw OFP data
- simbrief-ofp-manifest.json: OFP metadata, route and attachment index
- simbrief-ofp.pdf: original downloaded PDF
- ofp-pages/: optional *physical child gallery* of generated JPEG images

The local PDF is retained as the canonical original. Reimporting flight data does not automatically regenerate an existing OFP page gallery.

## Authorization and PDF serving

The sole supported PDF delivery endpoint is:

- View: index.php?page=media&id=GALLERY_ID&ofp=1
- Download: index.php?page=media&id=GALLERY_ID&ofp=1&download=1

The PHP controller validates gallery visibility and existing password/NSFW access decisions. The attachment service validates a known local filename, gallery ownership, manifest marker, symlink/path boundaries, 25 MiB size limit and PDF signature. Responses use application/pdf, no-sniff and private no-store caching. Other local filenames or URLs cannot be requested via this endpoint.

The galleries/.htaccess policy denies direct static PDF access. **Nginx/other servers ignoring Apache .htaccess require equivalent URL access restrictions for gallery PDF files.** Do not expose the gallery source folder without server-level access rules.

Import downloads only from HTTPS SimBrief URLs, rejects redirects, caps the response body to 25 MiB, enforces a 30-second timeout, and stages the completed download before replacing a prior file. On reimport failure, the previous imported PDF/snapshot remains intact.

## Document lightbox

The viewer uses locally distributed Mozilla PDF.js, with the original vendor license under public/assets/vendor/pdfjs. PDF.js runs without an external CDN or server-side rasterizer; eval-based PDF.js code generation is disabled.

The overlay shares the visual language of the existing photo lightbox, but owns its own **document-only page sequence**. Navigating from the first/last PDF page never enters the parent's photographs. It has:

- Document page counter and previous/next page navigation
- **Fit whole page** is the initial mode. It computes the smaller width/height scale from the actual scroll-stage client dimensions, canvas padding and clearance, centering the entire page without covering edges with the header or toolbar.
- **Fit width** is an explicit alternative allowing vertical scrolling. **Actual size (100%)** uses PDF.js's 96-CSS-pixel/inch scale. View-mode buttons expose the active automatic mode with `aria-pressed`.
- Zoom in/out reports percentages relative to the last fit; **Reset zoom (100%)** restores that fit. Pinch zoom, manual panning, fullscreen, and keyboard shortcuts remain independent of gallery-photo navigation.
- Automatic fit modes recompute for every PDF page's dimensions/orientation, stage resize, device rotation, and fullscreen transitions. Manual zoom retains its chosen CSS scale and scroll position across ordinary stage resizing until an explicit fit or reset.
- Responsive layout, lazy per-page rendering, bounded 16-megapixel high-DPI canvas
- Cleanup of page/worker resources on navigation and close
- Original PDF download link and browser-native PDF fallback

The normal photo lightbox, map and slideshow state are not modified by PDF page navigation.

## Optional server-side PDF page conversion

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

- tests/simbrief_ofp_lightbox_browser_test.mjs verifies portrait A4 and mixed-orientation whole-page geometry on a landscape stage, fit-width and actual-size modes, center/bounds, mode indication, manual resize persistence, simulated rotation/fullscreen changes, translations, download isolation and resource cleanup.
- tests/simbrief_ofp_document_test.php covers manifest validation, PDF signature and symlink refusal, generated-gallery ownership checks, no-op idempotency and the separate viewer contract.
- Existing gallery import/export, deletion, backup and thumbnail workflows should continue treating the OFP pages as an ordinary physical child gallery.
- New production assets and PHP services must appear in the checked-in production inventory and core manifest.
- A complete hosted browser/integration test must also exercise a real multipage SimBrief PDF, restricted galleries, the zoom UI, and optional Imagick on a capable host.
