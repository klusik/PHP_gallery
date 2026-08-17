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

## Presentation overrides

Migration `202608170001_smart_gallery_presentation.php` adds nullable `smart_galleries.presentation_json`. The document is versioned independently from the rule document. Missing, malformed, unknown-version, or invalid values inherit current site and Theme defaults rather than becoming unsafe or hardcoded presentation state.

Supported overrides are:

- grid columns and rows
- pagination enabled
- thumbnail minimum and maximum generated size
- responsive or progressive thumbnail rendering
- vertical or horizontal gallery-card layout for placed Smart Gallery cards
- metadata overlay visibility
- lightbox enabled
- lightbox browsing mode
- slideshow enabled
- Smart Gallery download enabled
- visitor voting enabled where the physical source gallery also permits voting

Sorting remains the existing Smart Gallery `sort_mode` and `sort_direction` fields.

Presentation inheritance is `Smart Gallery override > current Theme/site default`. It intentionally does not inherit presentation from a physical parent gallery because one Smart Gallery can have several physical placements and therefore has no unambiguous parent presentation owner.

Thumbnail bounds are an additional restriction. Physical gallery/image thumbnail guardrails remain authoritative if a Smart Gallery override conflicts with them.

Photo-card structure, spacing, and generated JPEG/WebP encoding quality remain the existing site/Theme behavior rather than separate Smart Gallery overrides because normal galleries do not expose separate per-gallery values for those properties. Placed Smart Gallery cards do honor the canonical vertical/horizontal gallery-card layout override. Smart Gallery photo results reuse the normal public photo-card markup and responsive/progressive thumbnail pipeline; the Smart Gallery minimum/maximum controls only narrow the generated-size candidates exposed to that pipeline.

## Admin editor and preview

The Smart Gallery editor works as a normal server-rendered form without JavaScript. The Admin side-panel module enhances the same forms in place. Create/edit links open the existing right-side drawer, POST submissions use the normal controller, redirects are followed with `fetch()`, the returned editor workspace is re-injected, and dynamic rules/presentation handlers are rebound without changing the browser URL.

The side-panel POST path is rewritten to the current browser origin for `admin_smart_galleries`. This preserves authenticated cookies when a local installation is opened through a MAMP/Laragon host alias or port that differs from the configured canonical base URL.

Preview queries actual matching images and renders cards through `smart_gallery_render_image_cards()`, the same card/presentation helper used by the public Smart Gallery page. Preview is limited to 12 matching images.

## Downloads

When both the global downloads feature and the Smart Gallery presentation setting allow it, the public page exposes an authorized Smart Gallery ZIP action. The server rebuilds the result set through the same access-aware query and streams the archive through the normal download controller. Filesystem paths are never exposed to the browser.

Smart Gallery ZIP generation applies two independent server-side resource limits: at most 5,000 matching images and an aggregate original-file byte ceiling. The byte ceiling is configured by `smart_gallery_zip_max_source_bytes`; existing installations without that key use a 2 GiB default. Archive builders acquire an exclusive lock scoped to the final content signature, re-check the cache after obtaining the lock, write a unique partial archive, and atomically rename it into the final cache path. This prevents concurrent requests from building the same ZIP into one destination. Failure logs retain only the Smart Gallery ID, exception class, and an allowlisted reason code; raw exception messages and filesystem/database details are not persisted.
