# TEMP: Smart Gallery source context, GPS parity, and aggregate maps

## Status

Implementation plan for extending Smart Galleries from a dynamic image-result view into a context-aware view that preserves each photo's physical gallery provenance and geographic metadata.

Current implementation status:

- Stage 1: **implemented**
- Stage 2: **implemented**
- Stage 3: **implemented in this checkpoint**
- Stages 4+: planned, not yet implemented

This file is intentionally temporary implementation documentation. Remove or fold it into `docs/SMART_GALLERIES.md` after all stages are complete and verified.

## Goals

A Smart Gallery result must remain a projection over canonical physical images. It must not become a second source of truth for image ownership, metadata, GPS policy, or authorization.

The public experience should be able to answer, for each matching photo:

1. Which physical gallery does this photo come from?
2. Can the visitor navigate to that physical gallery?
3. Does the source gallery allow this photo's GPS position to be exposed?
4. If GPS is allowed, where is the photo on a map?
5. For the Smart Gallery as a whole, where are all map-eligible matching photos located?

The implementation must preserve strict MVC ownership, avoid N+1 lookups, reuse the canonical Smart Gallery query compiler, and never allow Smart Gallery presentation settings to weaken source-gallery security/privacy policy.

## Non-goals

- Do not copy or persist source-gallery identity into a second Smart Gallery relation. `images.gallery_id` remains authoritative.
- Do not duplicate EXIF/GPS values into Smart Gallery persistence.
- Do not make Smart Gallery map policy override a source gallery that has maps/GPS disabled.
- Do not generate a second Smart Gallery rule evaluator for map queries.
- Do not expose filesystem paths.
- Do not make map data depend on the current paginated grid page.

## Existing architecture to reuse

The implementation already has the critical provenance relationship:

- Each Smart Gallery image row contains `gallery_id`.
- `smart_gallery_source_galleries()` batch-loads physical source galleries and therefore avoids an N+1 query pattern.
- Public Smart Gallery result membership already intersects rule matching with authorized physical gallery/image membership.
- The lazy Smart Gallery lightbox already resolves each image back to its source gallery.
- The lazy Smart Gallery lightbox already calls `gallery_allows_gps_maps($source)` when creating `map_point`.
- `gallery_lightbox_json_item()` already centralizes lazy-lightbox payload construction.
- `image_map_point()` already produces the canonical photo marker payload.
- `presentation_json` is the existing extensible Smart Gallery presentation document, so additive boolean presentation options do not require a new database column.

## Security and privacy invariants

These are mandatory across every stage.

### Provenance invariant

A Smart Gallery result references the physical source gallery from the authoritative image row:

```text
images.gallery_id -> physical gallery
```

No independent Smart Gallery source-gallery assignment may be introduced.

### Authorization invariant

A photo may appear in Smart Gallery cards, lightbox metadata, or map data only if the current request is authorized to access the source image/gallery under the existing public Smart Gallery membership policy.

### GPS invariant

GPS may be exposed only when all of the following are true:

```text
image matches Smart Gallery
AND visitor is authorized for the result
AND source gallery is authorized
AND gallery_allows_gps_maps(source gallery) = true
AND image contains valid GPS
```

A future Smart Gallery-level `map_enabled` preference may further suppress maps, but must never override a source gallery from OFF to ON.

### Localization invariant

Displayed source-gallery titles must use the active content language in the same request-local localization pass as current Smart Gallery source galleries.

### Canonical-query invariant

Grid results, counts, lazy-lightbox windows, map points, source summaries, and any future source-gallery filtering must derive from the same canonical Smart Gallery rule/query implementation. Do not duplicate rule-to-SQL compilation.

## Presentation model

Additive Smart Gallery presentation preferences:

```json
{
  "source_gallery_visible": true,
  "map_enabled": true
}
```

`source_gallery_visible` is implemented in Stage 1.

`map_enabled` is reserved for the aggregate-map stage. It should default ON once implemented, then be suppressed at runtime when the site-wide map capability or source policy makes map output unavailable.

The source-gallery indicator is navigational context, not photo metadata. It therefore remains independent from `metadata_visible`.

## Stage 1: Source-gallery context end-to-end

**Status: implemented in this checkpoint.**

### Service / presentation policy

Extend Smart Gallery presentation defaults and normalization with:

```text
source_gallery_visible = true
```

The preference is persisted in existing `presentation_json`. No schema migration is required.

### Admin editor

Add a Smart Gallery rendering preference:

```text
Show source gallery
```

It is part of presentation override state and defaults ON through inheritance.

### Public result cards

For each image card, the controller already has the localized `$source` gallery row. Prepare a presentation-only source context:

```text
source_gallery.id
source_gallery.title
source_gallery.url
```

The view renders a compact clickable source-gallery badge independent from photo metadata overlays. The badge must not expose filesystem paths or raw persistence details.

### Initial Smart Gallery lightbox items

Extend the shared lightbox data-attribute model with optional source-gallery context. Only Smart Gallery callers populate it. Normal physical-gallery lightboxes remain unchanged.

Required data fields:

```text
source_gallery_id
source_gallery_title
source_gallery_url
```

### Lazy Smart Gallery lightbox items

Extend `gallery_lightbox_json_item()` with an opt-in source-context parameter. When the Smart Gallery presentation enables source context, lazy JSON items include the same source-gallery fields as initial cards.

Normal physical-gallery lazy metadata uses the default OFF behavior, preserving existing UI.

### Shared lightbox UI

Add an initially hidden source-gallery container to the shared lightbox shell. Client code fills it only when the active card has source-gallery context. The link points to the physical gallery and is independent from the history-return URL used by the lightbox.

### CSS

Add narrowly scoped styles for:

- `.smart-gallery-source-badge`
- `.lightbox-source-gallery`

The card badge should remain compact, overlay the photo without changing card geometry, and remain keyboard-accessible.

### Tests

Add/extend regression coverage for:

- default ON behavior,
- explicit OFF normalization,
- Admin POST persistence,
- source context in initial card attributes,
- source context in lazy Smart Gallery payload,
- no forced source context in normal physical-gallery lazy payload,
- card view rendering,
- lightbox client hydration and active-photo rendering,
- translation keys.

## Stage 2: GPS parity for initial Smart Gallery cards

**Status: implemented in this checkpoint.**

### Existing inconsistency

Initial Smart Gallery cards currently call:

```php
lightbox_image_data_attributes(..., null, ...)
```

for the `imageMapPoint` argument.

Lazy Smart Gallery lightbox items already call:

```php
gallery_lightbox_json_item(
    $image,
    $source,
    ...,
    gallery_allows_gps_maps($source),
    ...
)
```

so lazy items can expose an authorized `map_point` while initially rendered items cannot.

### Implementation

For each initial card:

1. Determine `gallery_allows_gps_maps($source)`.
2. Check `image_has_gps($image)`.
3. Build `image_map_point($image, $source, true, $bundle)` only when both conditions are true.
4. Pass the resulting point to `lightbox_image_data_attributes()`.
5. Reuse the already loaded thumbnail bundle so the map thumbnail does not trigger redundant discovery.

### Verification

Assert parity between initial-card and lazy-lightbox GPS exposure for equivalent image/source policy.

## Stage 3: Canonical aggregate Smart Gallery map query

**Status: implemented in this checkpoint.**

### Requirement

The aggregate map represents the **entire Smart Gallery result set**, not only the current pagination page.

Example:

```text
Smart Gallery total: 1,842 images
Grid page: 24 images
Map: every authorized, map-eligible GPS result among all 1,842 images
```

### MVC ownership

#### Model

Add a model-owned GPS projection based on the existing canonical Smart Gallery result query. It should return only map-relevant fields and must not use `SELECT *`.

Expected projection should be limited to fields such as:

```text
image id
gallery id
GPS latitude/longitude
title/description fields required by marker UI
filename if required by canonical title fallback
width/height if required by thumbnail/marker generation
```

The model must reuse the same canonical rule predicate and stable authorization scope used by normal Smart Gallery result queries.

#### Service

The service should:

1. Resolve authorized source-gallery scope.
2. Intersect it with source galleries for which effective GPS-map presentation is allowed.
3. Execute the canonical GPS projection.
4. Batch-load/localize physical galleries.
5. Build map point DTOs without exposing unauthorized source metadata.
6. Apply a hard result guard and explicit runtime limits.

#### Controller

Add a dedicated public endpoint, conceptually:

```text
smart_gallery_map_data
```

Controller responsibilities:

- resolve public Smart Gallery,
- enforce public access,
- enforce Smart Gallery map presentation master/preference,
- call service,
- return bounded private/no-store JSON.

#### View

The public Smart Gallery hero receives a controller-prepared map action only when aggregate map functionality is available.

### Implemented Stage 3 details

- Added inherited `map_enabled = true` Smart Gallery presentation preference and site-wide `gallery_maps` capability suppression.
- Added a dedicated `smart_gallery_map_data` public JSON endpoint, gated by both `smart_galleries` and `gallery_maps`.
- Added `SMART_GALLERY_MAP_MAX_POINTS = 10000` as an explicit payload/materialization guard.
- Added canonical model-owned GPS count/query functions that reuse `smart_gallery_model_result_query()` and select only map-relevant image columns.
- Aggregate map queries intentionally omit grid `LIMIT/OFFSET`; the only limit is the dedicated map safety cap.
- Physical gallery rows are loaded once per request and reused for both Smart Gallery authorization and inherited GPS-policy resolution.
- `gallery_allows_gps_maps()` now accepts an optional preloaded gallery lookup callback, preserving existing behavior while avoiding parent-gallery N+1 queries in aggregate Smart Gallery maps.
- Map points reuse `image_map_point()` without thumbnails in this stage; marker thumbnails/provenance navigation are deferred to Stage 5.
- The JSON payload reports `total_images`, `gps_images`, `point_limit`, and `truncated` explicitly.

## Stage 4: Aggregate map UI

**Status: implemented in this checkpoint.**

### Hero action

Add a compact map action next to the existing download action.

The action should open the existing gallery/lightbox map infrastructure rather than create an unrelated map implementation.

### Payload shape

Use an explicit versionable object rather than returning an unstructured array:

```json
{
  "ok": true,
  "source_type": "smart_gallery",
  "smart_gallery_id": 12,
  "total_images": 1842,
  "gps_images": 731,
  "points": []
}
```

### Pagination independence

Do not include grid `LIMIT/OFFSET` in aggregate map semantics.

### Empty map

If a Smart Gallery has no authorized map points, do not render a misleading active map action. The endpoint must still fail safely if called directly.

### Implemented Stage 4 details

- Added `smart_gallery_has_map_payload()` as a count-only availability check, so the public page does not materialize marker DTOs before the visitor opens the map.
- Availability and payload generation share `smart_gallery_map_query_context()`, preventing authorization/GPS-policy drift between the hero action and JSON endpoint.
- Added a compact pin action beside the existing Smart Gallery download action.
- Reused the existing `data-gallery-map-url` browser contract and Leaflet overlay instead of introducing a second map implementation.
- The Smart Gallery lightbox receives the same aggregate map URL/title, so its Map control opens the complete Smart Gallery result map.
- The action is omitted when effective Smart Gallery map presentation is disabled or no authorized GPS result exists.
- Added localized EN/CS/DE/SV map-action labels.

## Stage 5: Map marker provenance and Smart Gallery navigation

**Status: implemented in this checkpoint.**

Every aggregate-map marker should preserve two navigation targets:

1. **Open photo in current Smart Gallery context** using the Smart Gallery global index/order.
2. **Open source gallery** using the localized physical gallery title and canonical public URL.

Marker popup concept:

```text
[thumbnail]
Photo title
📁 Stockholm 2025
Open photo
```

Opening the photo should keep Smart Gallery lightbox ordering. Opening the source gallery intentionally leaves Smart Gallery context.

The marker DTO may therefore need:

```text
smart_gallery_index
image_id
gallery_id
photo_page_url
source_gallery_title
source_gallery_url
```

Do not attempt to derive the global Smart Gallery index client-side from current page DOM.

### Implemented Stage 5 details

- Aggregate markers now carry `smart_gallery_id`, localized `source_gallery_title`, canonical `source_gallery_url`, and a lazy 300px thumbnail route.
- Popup rendering shows the source gallery as a separate navigable target while preserving the normal Open photo action.
- Smart Gallery popup photo actions are marked with `data-map-smart-gallery-context="1"`.
- The shared lightbox map-navigation pipeline can now open a Smart Gallery photo even when the aggregate map was opened from the hero while the lightbox itself is closed.
- Added `target_image_id` support to `smart_gallery_lightbox_data`. The client sends only the authoritative image id; it does not infer a global index from current-page DOM.
- Added `smart_gallery_image_position()` and model-side canonical index resolution. The position query mirrors the active sort expression plus the stable image-id tie breaker.
- Position lookup intentionally avoids SQL window functions so the existing MySQL 5.7+/MariaDB 10.2+ compatibility floor remains intact.
- The request guard now explicitly allows `target_image_id` for the Smart Gallery lightbox endpoint and its regression fixture verifies the new public contract.

## Stage 6: Map scaling and clustering

**Status: baseline guard already implemented; clustering remains measurement-driven.**

### Initial implementation

Use the existing Leaflet stack and measure real payload/render performance first.

### Scaling guard

Add explicit limits so a pathological Smart Gallery cannot emit unbounded marker JSON.

### Clustering

If real datasets show excessive marker count/render cost, add clustering without changing endpoint semantics. Prefer keeping the `points` DTO stable so clustering is a renderer concern.

If server-side spatial aggregation later becomes necessary, version the payload instead of silently changing point semantics.

## Stage 7: Source-gallery summary and optional source filtering

**Status: implemented in this checkpoint.**

Provide a source summary such as:

```text
428 photos from 12 galleries

Stockholm 2025    182
Arlanda            96
Uppsala            71
...
```

Implementation must use a model-owned grouped projection over the canonical result query rather than counting PHP result rows.

A temporary filter such as `Source: Stockholm 2025` refines the Smart Gallery view without mutating persisted Smart Gallery rules. It remains request/view state and is included consistently in grid, count, lightbox, and map semantics.

### Implemented Stage 7 details

- Added `smart_gallery_model_source_summary_rows()` as a single grouped `GROUP BY i.gallery_id` projection over `smart_gallery_model_result_query()`.
- The service localizes summary gallery titles from the request-cached physical-gallery rows, so summary rendering introduces no source-gallery N+1 lookup.
- Added `source_gallery_id` as temporary request state. It is not stored in `rules_json` or `presentation_json`.
- Source filtering narrows the already authorized `accessible_gallery_ids` scope to one physical gallery id. It does not compile a second image predicate or bypass canonical Smart Gallery rules.
- Public Smart Gallery count, paginated grid query, lazy lightbox windows, map availability, aggregate map payload, and map target position lookup all receive the same source filter.
- Aggregate map GPS-policy resolution short-circuits to the selected source gallery when a filter is active while preserving inherited `gallery_allows_gps_maps()` policy.
- Pagination links preserve the active source filter.
- Clean `/smart/<slug>` URLs preserve temporary filter state as a query parameter while the slug and photo page remain in the clean path.
- The public source summary is rendered as a native `<details>` block with count chips, an explicit All sources reset, active-state accessibility, and a separate physical-gallery navigation link.
- Direct invalid/unauthorized page filters fail closed through summary validation; JSON endpoints independently fail closed to an empty authorized result scope.
- The download action intentionally retains persisted Smart Gallery semantics in this stage. Source filtering is a view-state refinement for grid/lightbox/map, matching the scope defined by this plan.
- Added EN/CS/DE/SV labels and request-guard/URL-rewrite/public-contract regression coverage.

## Stage 8: Documentation, diagnostics, and final hardening

When implementation is complete:

1. Fold final architecture into `docs/SMART_GALLERIES.md`.
2. Add Admin/test-run diagnostics for source-context and map counts where useful.
3. Verify no N+1 source-gallery or map-thumbnail queries were introduced.
4. Verify translation coverage EN/CS/DE/SV.
5. Verify keyboard/mobile behavior of source badges and map controls.
6. Verify anonymous, authenticated viewer, Admin preview, private, unpublished, NSFW, and GPS-disabled combinations.
7. Verify Smart Gallery map never broadens source-gallery GPS disclosure.
8. Run the authoritative full audit before packaging affected files.
9. Remove this TEMP file only after the permanent documentation contains all final behavior and invariants.

## Suggested implementation checkpoints

Each stage should be independently commit-safe and should produce an affected-files ZIP.

```text
Checkpoint 1: Source context end-to-end
Checkpoint 2: Initial/lazy GPS parity
Checkpoint 3: Aggregate map model/service/controller endpoint
Checkpoint 4: Hero map action + aggregate map UI
Checkpoint 5: Marker provenance + Smart Gallery photo navigation
Checkpoint 6: Scaling/clustering if justified by measurements
Checkpoint 7: Source summary/filtering (optional)
Checkpoint 8: final docs + diagnostics + hardening
```

## Expected Stage 1 affected areas

```text
app/services/smart_galleries.php
app/controllers/smart_galleries.php
app/controllers/public_gallery_lightbox.php
app/controllers/gallery_lightbox.php
app/views/smart_galleries.php
app/views/public_gallery_lightbox.php
public/assets/gallery-modules/lightbox.js
public/assets/styles/public-shared.css
app/lang/{en,cs,de,sv}.json
tests/smart_gallery_presentation_test.php
tests/smart_gallery_public_contract_test.php
TEMP_SMART_GALLERY_SOURCE_CONTEXT_AND_MAPS.md
```

No database migration is expected for Stage 1.
