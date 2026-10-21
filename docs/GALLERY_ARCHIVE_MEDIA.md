# ZIP archive media opt-in and Smart Gallery media counts

Issue: [#276](https://github.com/klusik/PHP_gallery/issues/276)

## Authorization model

Normal gallery downloads remain image-only after both fresh installation and
upgrade. PDF and music originals require an explicit archive preference. For
each source gallery and media item, the ZIP inclusion expression is:

```text
eligible_for_archive =
    global_allow[kind] OR gallery_allow[source_id, kind]
    OR item_allow[source_id, kind, item_id]
```

The inclusion expression **does not grant authorization**. It is evaluated
after/alongside the established visitor, effective gallery visibility, inherited
password/share access, published Smart Gallery result membership, hidden state,
PDF/Music master capability, storage source ownership and MIME/integrity
requirements. Every media-original request re-evaluates these rules, including
when a capability was issued before a preference or visibility change.

Preferences are persisted in existing `app_settings` without schema changes.
Missing or corrupt optional values fail closed, defaulting to OFF:

| Scope | PDF key | Music key |
| --- | --- | --- |
| Global | `download_archive_pdf_global` | `download_archive_music_global` |
| Gallery | `download_archive_pdf_gallery_<id>` | `download_archive_music_gallery_<id>` |
| Item map | `download_archive_pdf_items_<id>` | `download_archive_music_items_<id>` |

Item maps use a bounded JSON dictionary of opaque document, song, delivery or
album-assignment IDs set to `true`. The reserved PDF OFP identity is `ofp`.
Global and gallery preferences never erase lower-level saved choices.

The legacy Music `downloadable` assignment flag is an independent policy for
standalone music download URLs. It is intentionally not treated as implicit ZIP
opt-in, because that would include audio on upgraded installations with no new
archive preferences. PDF standalone original download URLs remain independent
of archive switches too.

## User interface

Administrators can toggle both media kinds independently in **Admin >
Settings > Media**, at the physical gallery level in **Edit Gallery > Media**,
and at the original PDF/song/album level next to the existing item controls.
Gallery and item switches expose their persisted values even while the global
switch takes precedence.

Item inclusion actions use the existing compact SVG controls. Their accessible
pressed state reflects the saved item preference independently of any global or
gallery override; the action label describes the change that submission makes.

## Progressive download and server fallback

The existing browser ZIP manifest schema has additional optional descriptors
for original `pdf` or `music` assets, with a source gallery ID and opaque
original item ID. The photo-only manifest shape remains unchanged.

* Original media entries live under `PDF/` and `Music/`, followed by the
  normalized physical gallery path and original filename. Existing filename
  extensions are preserved.
* A content digest is used for original versioning and deduplication of identical
  content within each media type. Collision suffixes ensure unique case-insensitive
  archive paths; no source absolute paths are sent to the browser.
* The current manifest checks source gallery access and Smart result provenance.
  Download byte endpoints independently authorize the same media identity and
  source after validating the short-lived progressive capability.
* The existing count/byte ceilings, in-browser progress and cancellation, ZIP64
  writer and bounded server ZIP fallback remain applicable to optional media.
* The admin all-gallery export also includes explicitly permitted media; its
  content signature changes with the selected source set.

Smart downloads use physical sources that **actually contribute authorized
matching image records**. They never enumerate unrelated galleries merely
because those galleries are mentioned in a query rule. A Smart gallery's
opened source filter remains a photo-view filter, while its media attachment
union is based on its complete canonical result, not the current paginated page.

## Display counts

Public Smart Gallery cards and opened hero panels use the same PDF/music
count icons as physical galleries. Their source membership comes from the
canonical Smart rule result and the durable `gallery_attachment_usage`
projection. The counters honor media capability, public visibility,
hidden-state and SHA-256 content deduplication. Dirty or missing projections
produce **unknown**, not zero, and the badge is omitted. ZIP inclusion settings
never affect counts.

## Security and validation checklist

* Missing settings on fresh installs/upgrades leave ZIPs photo-only.
* Toggle independence and precedence: global, gallery, item, all disabled.
* Hidden/unpublished/inaccessible PDFs or music remain excluded with global ON.
* A previously issued capability cannot bypass a later item revocation.
* Smart sources excluded by current rules never contribute attachments.
* Both ZIP builders agree on paths, originals, size and deduplication.
* Standalone original download actions retain their prior policy.
* Source paths reject traversal, symlinks and invalid storage indexes.
* Stale manifests reject changed size/version, and stale server ZIP results
  are not served after a preference/authorization change.
* PDF/music counters remain independent of archive preferences and do not
  trigger per-visitor source hashing.
* Root and nested Smart attachment cards no longer inherit an extra panel
  background, while article, grid, focus and pointer structure remain intact.

See `docs/GALLERY_PDF.md`, `docs/GALLERY_MUSIC.md`,
`docs/SMART_GALLERIES.md`, and the four authored TeX manuals for related
media handling and UI usage.
