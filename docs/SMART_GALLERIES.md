# Smart Galleries

Smart Galleries are saved dynamic image queries. They do not copy image rows or files. `app/services/smart_galleries.php` owns rule validation, access-aware result selection, ordering, presentation normalization, counting, database pagination, and lazy lightbox windows.

## Public visibility and access

A Smart Gallery is publicly addressable only when its definition is enabled and has `visibility = public`. Root and physical-gallery placements use the same requirement. Direct query-string and clean `/smart/<slug>` URLs remain supported.

For anonymous/public result queries, every candidate physical source gallery must satisfy both `gallery_is_public_listed()` and `visitor_can_access_gallery()`. Candidate images must also have public image visibility. Existing password, share-link, inherited visibility, unpublished-gallery, and NSFW decisions therefore remain authoritative. Counts, public cards, page rows, lazy lightbox windows, and Smart Gallery ZIP downloads all derive from this same service-layer result set.

The same Smart Gallery may be attached beneath multiple physical galleries. Placements do not duplicate its result set and removing one placement does not change any other placement.

## Cycle safety

Smart Gallery relationships are validated as a mixed directed graph of stable IDs. Each physical attachment contributes `gallery -> Smart Gallery`. Positive physical-gallery references in a Smart Gallery rule contribute `Smart Gallery -> gallery`: `equals` contributes only the exact gallery, while `under` is expanded through the current or proposed physical hierarchy to the referenced gallery plus its descendants. Gallery exclusion rules do not create an inclusion edge. The current rule catalog has no Smart-Gallery-reference condition, so the implementation does not invent unsupported Smart-Gallery-to-Smart-Gallery semantics.

Rule edits, attachment replacements, single physical-gallery moves, complete Admin drag-and-drop parent maps, filesystem-derived parent synchronization, and public-path hierarchy repair are evaluated against the proposed graph before any new `parent_id` write or first filesystem move. A new path from an attached Smart Gallery back to its physical parent is a cycle and is rejected. Existing unrelated legacy cycles do not prevent an administrator from making a repair elsewhere: hierarchy validation compares current and proposed diagnostics and rejects newly introduced invalid relationships. Physical hierarchy writes clear the request-local Smart Gallery graph cache immediately. Admin drag-and-drop validates the complete final tree once and then uses an explicit prevalidated batch path, avoiding false rejection of temporary intermediate states while folders are moved.

Runtime remains defensive if legacy data bypassed validation. The request-local graph snapshot deduplicates visited IDs and is bounded to 64 traversal depth, 4,096 expanded relationship nodes, 1,024 expanded Smart Gallery nodes, 20,000 graph edges, and 50,000 source rows per graph entity query. One physical gallery may submit at most 100 Smart Gallery attachments. Invalid placed relationships are omitted from public card groups and emit at most ten safe relationship diagnostics per request; Admin attachment lists mark them as needing repair. Detach does not require the invalid graph to become valid first, so repair remains possible. Flat direct/root result evaluation does not follow attachment edges, so an invalid attachment does not hide an otherwise valid Smart Gallery result view. Malformed rule definitions still fail closed.

These graph limits do not materialize image membership. Smart Gallery SQL remains the canonical rule query, with page results capped at 200 rows and lazy-lightbox windows capped at 80. Repeated graph construction is avoided with request-local caching that is cleared after relationship mutations. Public Smart Gallery card count/cover context is also cached per Smart Gallery ID within one render request.

## Per-parent placement and ordering

Migration `202608170002_smart_gallery_attachment_ordering.php` extends `smart_gallery_placements` with `placement` and `placement_order`. `placement` is allowlisted to `top` or `bottom`; existing and new rows default to `bottom`, preserving the previous below-content behavior. `placement_order` defaults to `0` and is normalized to a bounded integer. The existing composite primary key still permits one Smart Gallery under several physical parents but only one instance under any single parent.

Each physical parent is rendered in three boundaries: ordered top Smart Gallery cards, normal physical subgallery/photo content, then ordered bottom Smart Gallery cards. Top and bottom are sorted independently by `placement_order`, with Smart Gallery ID as the stable tie breaker. Smart Gallery attachments no longer consume the physical child-gallery pagination slice. A hidden, private, disabled, cyclic, or otherwise unavailable attachment is filtered before the group is emitted, so it leaves no blank public panel.

The physical gallery editor shows current Top/Bottom groups plus placement/order controls for every Smart Gallery. The Smart Gallery editor shows every physical parent and lets the administrator update that parent's placement/order or detach it. These are ordinary server forms and therefore work without JavaScript; inside the Admin drawer, existing delegated AJAX handlers submit and refresh the owned panel without changing the browser URL. If the new columns are missing or their schema state is unknown, reads retain bottom/zero compatibility but attachment mutations are refused before any junction row is deleted.

## Stable result ordering

`smart_gallery_result_query()` compiles the versioned rule document, intersects it with viewer-accessible physical gallery IDs, and supplies the safe allowlisted `ORDER BY` expression. Every sort mode includes `images.id` in the same direction as a deterministic tie breaker. `smart_gallery_count_images()`, `smart_gallery_query_images()`, `smart_gallery_lightbox_fetch_images()`, preview, public rendering, card counts, and downloads all consume this canonical query contract.

Normal database page queries are capped at 200 image rows. Public presentation can disable visible pagination for small result sets, but results above that cap force server pagination as a memory and response-size safeguard.

## Complete lightbox navigation

Public Smart Gallery cards carry their global result index, not merely their page-local index. The page publishes the full authorized result count and an authenticated `smart_gallery_lightbox_data` endpoint. The existing lightbox sparse cache requests nearby metadata windows as visitors move outside the current HTML page.

Each endpoint request is capped at 80 metadata records and uses the same access predicate, Smart Gallery rules, sort mode, sort direction, and image-id tie breaker as public rendering. It does not preload original files or the complete result set. Existing lightbox pending-request, stale-result, keyboard, touch, fullscreen, zoom, and slideshow lifecycle logic remains shared with normal galleries.

When the Smart Gallery presentation disables slideshow, slideshow controls are omitted and the `S` shortcut becomes a no-op for that viewer instance. Normal galleries retain slideshow behavior by default.

## Source-gallery provenance and temporary source filtering

Every Smart Gallery result remains a physical image owned by exactly one physical source gallery. The public controller resolves those physical gallery rows in one request-cached lookup and passes localized source context into the card/lightbox view model. No Smart Gallery-specific provenance table or duplicated source identifier is persisted.

When `source_gallery_visible` is enabled, public photo cards show a keyboard-focusable source-gallery context chip and initial/lazy lightbox metadata expose the same localized source title, structural breadcrumb, and canonical physical-gallery URL. Breadcrumbs follow the normal physical-gallery hierarchy and are resolved from the request-cached gallery inventory, with ancestor translations loaded in one batch. Cards use the final two breadcrumb segments for compact context such as `Friedrichshafen › DEN 01`; the lightbox and map can display the complete path. The shared lightbox only renders this provenance when the active item supplies it, so ordinary physical galleries do not acquire a redundant self-reference. Source-chip clicks are excluded from the image-card lightbox interception path and therefore navigate directly to the physical gallery.

Logged-in public pages load the same `public-shared.css` visitor presentation layer as anonymous public pages in addition to their Admin tooling styles. This keeps Smart Gallery provenance, source filters, lightbox source context, and other shared visitor-facing components visually consistent while preserving Admin-only styles for inline controls.

The source summary is computed with one model-owned `GROUP BY i.gallery_id` projection over the canonical Smart Gallery predicate. It reports the complete authorized source distribution without loading all result images into PHP. A temporary `source_gallery_id` query parameter can refine the public grid. This filter is request/view state only: it is never persisted in `rules_json` or `presentation_json`. The service applies it by narrowing the already-authorized physical gallery-id scope, so count, grid pagination, lazy lightbox, image-position lookup, map availability, and aggregate map payload all continue to use the same canonical result-query compiler.

The source filter intentionally does not change Smart Gallery download semantics. Downloads continue to represent the persisted Smart Gallery definition rather than an incidental public-view filter.

## Aggregate GPS maps

Smart Gallery maps are aggregate views over the complete authorized Smart Gallery result set, independent from grid pagination. `smart_gallery_map_query_context()` starts from the same physical-gallery access scope used by the normal result query, then restricts that scope to source galleries whose effective inherited GPS-map policy allows disclosure. The resulting gallery-id set is passed back through `smart_gallery_query_semantics()` and the canonical model predicate.

The map endpoint never broadens source-gallery disclosure. A point is eligible only when all of the following are true:

- the Smart Gallery itself is publicly addressable and its effective `map_enabled` presentation is true;
- the physical source gallery is public/listed and accessible to the current visitor;
- the physical source gallery's effective inherited GPS-map policy allows maps;
- the image remains a member of the canonical Smart Gallery rule result after public image visibility and NSFW policy;
- both GPS coordinates are present.

A source gallery with GPS maps disabled therefore contributes zero aggregate markers even when its images match the Smart Gallery. Private, unpublished, password/share-inaccessible, or otherwise unauthorized physical galleries likewise contribute neither result rows nor map points to an anonymous visitor. Authenticated viewer access may widen only through the existing `visitor_can_access_gallery()` policy. Admin preview uses the separate `publicOnly = false` result semantics and does not weaken the public map endpoint.

The initial HTML card path and lazy lightbox metadata path both call the same physical-gallery GPS authorization before exposing an individual `map_point`, preventing first-page/lazy-window privacy drift.

The aggregate endpoint returns a bounded DTO with `total_images`, `gps_images`, `point_limit`, `truncated`, and `points`. GPS count and marker projection are model-owned projections over the canonical result query. Marker projection deliberately avoids `SELECT *`. Source-gallery rows are request-cached and reused for inherited GPS-policy walking and marker provenance, avoiding per-marker source-gallery queries. Popup thumbnails are lazy public thumbnail URLs and are not generated eagerly by the map query itself.

The current hard cap is 10,000 markers. Existing Leaflet rendering is retained until real measurements justify clustering. If clustering is added later, the point DTO should remain stable where possible; any server-side spatial aggregation that changes point semantics requires an explicit payload version.

Map popup actions preserve Smart Gallery ordering. The browser sends the authoritative `target_image_id`; the server resolves its exact zero-based position through the canonical order including the image-id tie breaker. The client never guesses a global index from the current page DOM. The position query intentionally avoids SQL window functions to preserve the MySQL 5.7+/MariaDB 10.2+ compatibility floor. A separate popup link opens the physical source gallery and intentionally leaves Smart Gallery context.

## Diagnostics and performance verification

Admin Test Run records a bounded `smart_gallery` component for Smart Gallery page requests. In addition to page/count/pagination information it records source-context coverage and aggregate-map counts: whether source context is enabled, how many rendered page cards carry source context, source-summary counts, selected source filter state, whether the map is enabled/available, GPS-image count, number of GPS-policy-eligible source galleries, point cap, and truncation state. These diagnostics contain counts and identifiers only; they do not persist GPS coordinates, filesystem paths, credentials, or marker payloads.

The normal Smart Gallery page obtains map availability from the same count-only map diagnostic path. It does not materialize marker rows or popup thumbnails before the visitor opens the map. Admin Test Run's existing SQL fingerprint analysis remains the authoritative way to detect repeated-query/N+1 regressions. Expected source-gallery behavior is one request-cached physical-gallery inventory rather than a query per result image or map marker.

Source badges, source-filter links, native `<summary>` disclosure, and the map action are native anchors/buttons with visible focus styling. The source controls retain wrapping/ellipsis behavior on narrow layouts, and coarse-pointer sizing must preserve a practical touch target without changing the underlying keyboard semantics.

## Presentation overrides

Migration `202608170001_smart_gallery_presentation.php` adds nullable `smart_galleries.presentation_json`. The document is versioned independently from the rule document. Missing, malformed, unknown-version, or invalid values inherit current site and Theme defaults rather than becoming unsafe or hardcoded presentation state.

Supported overrides are:

- grid columns and rows
- pagination enabled
- thumbnail minimum and maximum generated size
- responsive or progressive thumbnail rendering
- vertical or horizontal gallery-card layout for placed Smart Gallery cards
- metadata overlay visibility
- source-gallery provenance visibility
- aggregate GPS map enabled
- lightbox enabled
- lightbox browsing mode
- slideshow enabled
- Smart Gallery download enabled
- visitor voting enabled where the physical source gallery also permits voting

Sorting remains the existing Smart Gallery `sort_mode` and `sort_direction` fields.

Presentation inheritance is `Smart Gallery override > current Theme/site default`. It intentionally does not inherit presentation from a physical parent gallery because one Smart Gallery can have several physical placements and therefore has no unambiguous parent presentation owner. Admin editing resolves this preference state without applying site-wide capability masters. Lightbox, downloads, and voting masters are applied only when the public or preview runtime computes the effective presentation, so temporarily disabling a global capability cannot erase a Smart Gallery's stored local preference during an unrelated edit.

Current-version Smart Gallery mutations require the `presentation_json` column to be present before persistence starts. The model always includes `presentation_json` in INSERT and UPDATE statements. An installation that has not applied the presentation migration therefore refuses the mutation instead of partially saving title, sorting, rules, or placement while silently dropping presentation changes. Read-side normalization remains defensive for legacy null, malformed, or unknown-version presentation documents.

Thumbnail bounds are an additional restriction. Physical gallery/image thumbnail guardrails remain authoritative if a Smart Gallery override conflicts with them.

Photo-card structure, spacing, and generated JPEG/WebP encoding quality remain the existing site/Theme behavior rather than separate Smart Gallery overrides because normal galleries do not expose separate per-gallery values for those properties. Placed Smart Gallery cards do honor the canonical vertical/horizontal gallery-card layout override. Smart Gallery photo results reuse the normal public photo-card markup and responsive/progressive thumbnail pipeline; the Smart Gallery minimum/maximum controls only narrow the generated-size candidates exposed to that pipeline.

## Admin editor and preview

The Smart Gallery editor works as a normal server-rendered form without JavaScript. The Admin side-panel module enhances the same forms in place. Create/edit links open the existing right-side drawer, POST submissions use the normal controller, redirects are followed with `fetch()`, the returned editor workspace is re-injected, and dynamic rules/presentation handlers are rebound without changing the browser URL. Presentation grid columns and rows use the same bounded range-control pattern as physical galleries, including live values and items-per-page feedback. Rows are preserved while pagination is disabled and become active again when pagination is enabled; large Smart Gallery result sets may still be paginated by the server safety cap. Thumbnail minimum/maximum values reuse the canonical dual-bound slider and POST normalizer used by physical galleries.

The side-panel POST path is rewritten to the current browser origin for `admin_smart_galleries`. This preserves authenticated cookies when a local installation is opened through a MAMP/Laragon host alias or port that differs from the configured canonical base URL.

Preview queries actual matching images and renders cards through `smart_gallery_render_image_cards()`, the same card/presentation helper used by the public Smart Gallery page. Preview is limited to 12 matching images.

## Downloads

When both the global downloads feature and the Smart Gallery presentation setting allow it, the public page exposes an authorized Smart Gallery ZIP action. The server rebuilds the result set through the same access-aware query and streams the archive through the normal download controller. Filesystem paths are never exposed to the browser.

Smart Gallery ZIP generation applies two independent server-side resource limits: at most 5,000 matching images and an aggregate original-file byte ceiling. The byte ceiling is configured by `smart_gallery_zip_max_source_bytes`; existing installations without that key use a 2 GiB default. Archive builders acquire an exclusive lock scoped to the final content signature, re-check the cache after obtaining the lock, write a unique partial archive, and atomically rename it into the final cache path. This prevents concurrent requests from building the same ZIP into one destination. Failure logs retain only the Smart Gallery ID, exception class, and an allowlisted reason code; raw exception messages and filesystem/database details are not persisted.
