# TEMP - Progressive Public Search Redesign and Performance Specification

Status: specification only, no implementation in this file  
Target project: PHP Gallery  
Target runtime: current PHP 8.x / MariaDB architecture, no Composer dependency required  
Primary goal: make public search feel immediate on a large installation while preserving or improving result quality and keeping expensive deep search work out of the critical path

## 1. Purpose

This document defines a staged redesign of the existing public live search.

The current search is functionally useful and returns good results, but it performs broad substring matching across galleries, images, tags, descriptions, optional translations, and optional AI metadata before returning anything to the browser. On a large installation this makes the first visible result depend on the slowest parts of the search pipeline.

The redesign must change that behavior.

The target user experience is:

1. The user types a query.
2. The application quickly searches high-value, low-cost sources such as gallery titles and gallery tags.
3. The browser immediately displays useful results as soon as the first search phase completes.
4. Additional phases continue in the background.
5. Image title, filename, image tag, description, translation, and AI metadata results are merged into the visible result set as they arrive.
6. Expensive deep search is never required before the user can see an obvious gallery or tag match.
7. If the user changes the query, results from older requests must never overwrite or contaminate the new query.

The design is intentionally progressive. The first implementation stages must improve the existing SQL and browser behavior without requiring an external search service or a new search index. Later stages may introduce a rebuildable search index and optional FULLTEXT or n-gram acceleration if measurements show that it is useful.

This is not a request to replace MariaDB with Elasticsearch, OpenSearch, Meilisearch, Typesense, or another external service. The initial and preferred architecture remains self-contained and compatible with shared hosting.

## 2. Non-goals

The following are explicitly outside the first implementation scope:

- Replacing MariaDB as the source of truth.
- Requiring a persistent external search daemon.
- Changing gallery visibility or access semantics.
- Exposing private, unpublished, unlisted, password-protected, or otherwise unauthorized content through search.
- Removing the existing public search feature capability or creating a duplicate search feature flag.
- Changing Smart Gallery semantics unless needed for compatibility with the new search endpoint contract.
- Turning search into a semantic/vector search system.
- Changing image storage, thumbnail generation, media delivery, or gallery routing.
- Using browser-side indexing of the entire gallery dataset.
- Generating search data from source image binary contents.

## 3. Current implementation baseline

The current implementation is centered on:

- `app/services/public_search.php`
- `app/controllers/public_gallery_home.php`
- `public/assets/gallery-modules/public-home-search.js`
- public search styling in the existing public CSS assets
- the existing `public_search` feature capability and `public_home_search_enabled` setting

The current endpoint calls:

- `public_search_results($query, 14, public_search_context_from_request())`

`public_search_results()` currently runs both:

- `public_search_gallery_results()`
- `public_search_image_results()`

before sending a response to the browser.

The browser currently waits for that single response and displays either:

- a loading message,
- one final merged result set,
- an empty state,
- or an error state.

The browser uses a 200 ms debounce and `AbortController` to cancel superseded fetches.

This browser cancellation is useful but must not be treated as a guarantee that an already executing PHP/MariaDB query has stopped immediately. The redesign must reduce unnecessary expensive requests before they are sent and must independently reject stale responses on the client.

## 4. Current database behavior and identified bottlenecks

### 4.1 Leading-wildcard substring matching

The current helper creates search patterns equivalent to:

`%query%`

This preserves useful substring behavior, such as:

- query `320` matching gallery title `A320`
- query `172` matching `C172`
- query `ero` matching a registration or filename containing `ERO`

However, normal B-tree indexes generally cannot provide an efficient index seek for a leading-wildcard predicate such as `%320%`.

The redesign must preserve this useful behavior where practical, but it must stop requiring broad substring scans across the largest tables before any result can be displayed.

### 4.2 Gallery query join multiplication

The current gallery search joins galleries to public images, optional AI metadata, gallery tags, image tags, and tag rows, then aggregates with `GROUP BY`, `GROUP_CONCAT`, `COUNT(DISTINCT ...)`, and `MAX(CASE ...)` expressions.

This can create a large intermediate row set before the database can reduce it back to one row per gallery.

A gallery with many images and multiple tags can multiply rows substantially before aggregation. If multiple AI metadata records exist per image because different model/version combinations are stored, this can multiply work further.

The redesign must avoid joining all detail tables before candidate gallery IDs are known.

### 4.3 Image query treats parent gallery matches as image matches

The current image search contains parent gallery predicates such as a gallery title substring match.

Therefore, when query `320` matches gallery `A320`, every public image in that gallery can become an image candidate even if the image itself has no matching title, filename, description, tag, translation, or AI metadata.

This is expensive and also weakens result semantics. A gallery match should normally be represented as a gallery result, not as an implicit match for every image inside that gallery.

The progressive search redesign must remove this broad propagation from parent gallery title/description/tag matches into ordinary image result eligibility unless a narrowly defined product requirement later justifies it.

### 4.4 Detail aggregation happens before candidate reduction

The current SQL retrieves and aggregates display data while also discovering candidates.

This forces operations such as tag aggregation and joined gallery data construction to execute across many rows that will never appear in the final result list.

The target architecture must separate:

1. candidate discovery and scoring,
2. candidate limit/ranking,
3. detail hydration for only the selected IDs.

### 4.5 AI searchable text is potentially expensive

When AI image metadata is enabled and its schema is ready, `image_ai_metadata.searchable_text` participates in substring search.

This is a `MEDIUMTEXT` field and is not currently a dedicated FULLTEXT search index. Broad `%query%` searches on a large AI metadata corpus can be expensive.

AI metadata must therefore be placed in a late deep-search phase and must never block the first gallery/tag results.

### 4.6 Localized content is useful but belongs outside the initial critical path

Image and gallery translations currently participate in search when content localization is enabled and the required schema is available.

Localized searching must remain supported. However, description-heavy localized matching does not need to block the first title/tag response.

### 4.7 Existing useful indexes must remain useful

The existing schema includes useful relationship and visibility indexes, including indexes around:

- gallery parent/visibility/listing data,
- image gallery/visibility ordering,
- tag names,
- `gallery_tags` relation directions,
- `image_tags` relation directions,
- AI metadata image/model lookup.

The redesign must use these existing relationship indexes where possible and add new indexes only when justified by actual query plans.

Do not add speculative indexes simply because a column appears in a search predicate. In particular, a normal index on a column does not automatically solve `%query%` matching.

## 5. Core design principle

The new search must optimize for time to first useful result, not merely time to exhaustive completion.

The browser should be able to show a likely answer while deeper search is still running.

The conceptual pipeline is:

`query -> primary search -> visible result -> media search -> merged result -> deep search -> final merged result`

The application must prefer high-relevance and low-cost sources before low-relevance and high-cost sources.

The system must not interpret "progressive" as "run every expensive phase immediately in parallel". If every phase starts at once on every keystroke, database load remains high and the UX benefit is only cosmetic.

Progressive search must include request scheduling and deferral.

## 6. Proposed search phases

The exact internal function names may be adjusted during implementation to match existing naming conventions, but the behavioral boundaries below are required.

### Phase A - Primary gallery and gallery-tag search

Purpose: return the most likely navigation target as quickly as possible.

Search sources, in approximate order of relevance:

1. exact gallery title
2. normalized exact gallery title
3. gallery title prefix
4. exact gallery tag name
5. gallery tag prefix
6. gallery title substring
7. gallery tag substring
8. optional gallery slug match where useful and safe
9. gallery title translation match where localization is enabled and can be queried cheaply

Phase A must not join through all images in a gallery.

Phase A should normally touch only:

- `galleries`
- `gallery_tags`
- `tags`
- optionally `gallery_translations`

A gallery should be returned once even when it matches multiple tags.

Gallery subtitle/detail hydration should occur only for the selected small set of gallery IDs. Do not aggregate all image tags merely to render a gallery result subtitle.

Expected behavior:

- `320` should quickly surface `A320` if it is a publicly listable gallery.
- `letadlo` should quickly surface gallery matches or galleries associated with a matching gallery tag.
- A gallery match should not require scanning image descriptions or AI metadata.

### Phase B - Lightweight image metadata search

Purpose: find direct image matches without using the most expensive text sources.

Search sources:

1. exact image title
2. exact filename
3. normalized exact image title/filename where normalization is introduced
4. image title prefix
5. filename prefix
6. exact image tag
7. image tag prefix
8. image title substring
9. filename substring
10. image tag substring

Phase B must not make an image eligible solely because its parent gallery title, description, or gallery tag matches the query.

Parent gallery data is still required for authorization, URL generation, and result subtitle display, but it is context for the image result, not a broad match source.

Phase B should avoid searching:

- image description
- gallery description
- AI searchable text
- long translated descriptions

unless profiling later proves one of those fields is cheap enough to move earlier.

### Phase C - Gallery descriptive search

Purpose: enrich gallery discovery when the user searches terms that appear in descriptive metadata rather than names/tags.

Possible sources:

- gallery description
- gallery translated title/description
- gallery tag description if it remains valuable

This phase may be merged with Phase A in the final implementation if profiling proves the cost is negligible, but the implementation must keep a logical boundary so it can be deferred later without a broad rewrite.

### Phase D - Deep image search

Purpose: preserve the current ability to discover images using richer metadata.

Search sources may include:

- image description
- image translated title/description
- AI `searchable_text`
- image tag description
- any additional explicitly approved searchable metadata

This phase is intentionally late and may be scheduled only after the query has remained stable for a longer period.

The browser must already be able to display Phase A results before Phase D completes.

### Optional future Phase E - Search-index backed deep search

After the progressive architecture is stable, a rebuildable denormalized search index may replace some Phase B/C/D queries.

This is described later in this specification and must not be a prerequisite for the first progressive-search release.

## 7. Request scheduling and timing

The implementation must avoid firing all phases on every short-lived intermediate query.

A recommended starting policy is:

- retain a base input debounce near the existing 200 ms unless measurements justify adjustment,
- issue Phase A immediately after the base debounce,
- issue Phase B shortly after Phase A scheduling, for example roughly 100 to 200 ms later,
- issue descriptive/deep phases only after the query has remained unchanged for a longer quiet period, for example roughly 350 to 500 ms after the base request,
- cancel scheduled phases when the query changes before they start,
- abort in-flight browser fetches when superseded,
- independently discard stale responses even if the network abort does not stop server-side execution.

These are initial engineering targets, not hard-coded product constants. Final values must be based on local and production-like measurements.

A key requirement is that a user typing:

`3` -> `32` -> `320`

must not trigger a completed deep AI/description search for `32` if the user has already moved on to `320` before the deep phase is scheduled.

## 8. Endpoint/API architecture

The preferred first implementation is to keep the existing public search route and extend its request contract rather than adding multiple unrelated routes.

A request should identify the desired phase, for example conceptually:

- `phase=primary`
- `phase=media`
- `phase=descriptive`
- `phase=deep`

Exact parameter names can be chosen during implementation, but they must be stable and covered by tests.

The existing query and context parameters must continue to work:

- `q`
- `gallery_id`
- `context_only=1`

Context-only search must apply equivalent branch restrictions to every phase.

### 8.1 Response contract

Each phase response should provide enough information for deterministic client-side merging.

Each result should have a stable identity, for example:

- gallery result: `gallery:<id>`
- image result: `photo:<id>`

Recommended result model fields:

- stable result key
- `type`
- translated `label`
- `title`
- `subtitle`
- `url`
- relevance/rank value or stable rank class required for cross-phase ordering
- optional match-source metadata for diagnostics, not necessarily displayed

Recommended phase envelope fields:

- `ok`
- normalized `query`
- phase identifier
- result array
- phase completion indicator if useful
- Smart Gallery action data where appropriate

Do not expose SQL, query plans, database exceptions, internal paths, authorization data, passwords, tokens, or private metadata in the public response.

### 8.2 Compatibility mode

During migration it may be useful to keep the existing no-phase request behavior as a compatibility mode that returns a complete result set.

If retained, compatibility mode must call the new internal phase services rather than maintaining a duplicate old query implementation indefinitely.

The long-term goal is one search domain implementation with multiple phase entry points, not two separate search engines.

## 9. Browser orchestration

`public/assets/gallery-modules/public-home-search.js` must evolve from a single-request renderer into a query-session coordinator.

### 9.1 Search generation/session

Every accepted input query must create a new local search generation or session ID.

Example concept:

- generation 41: `32`
- generation 42: `320`

Every asynchronous phase completion must verify that it still belongs to the active generation before mutating the DOM.

This check is mandatory even when `AbortController` is used.

### 9.2 Abort ownership

A single controller variable is not sufficient once multiple phases may overlap.

Use an owned set/map of phase controllers or one query-level cancellation structure capable of aborting all requests belonging to the superseded generation.

When the query changes, clear action is pressed, Escape is used, or the query becomes shorter than the minimum length:

- cancel pending timers,
- abort active phase requests,
- increment/invalidate the generation,
- clear or hide results according to existing UX expectations.

### 9.3 Progressive rendering

The current behavior replaces the complete result container when one response arrives.

The new browser code must merge results from multiple phases.

Requirements:

- Phase A can render immediately.
- Later results must not clear valid earlier results.
- Duplicate stable result IDs must be merged/deduplicated.
- A later phase may improve metadata or relevance for an already present item if the server contract allows it.
- Reordering should be stable and intentional, not visually chaotic.
- The user must not see old-query results flash into the current query.

### 9.4 Loading state

Do not use a single blocking "Searching..." state that hides useful results until all phases are done.

Preferred behavior:

- before any result exists, show the existing loading state or a compact equivalent,
- after Phase A returns results, show those results immediately,
- indicate that deeper search is still continuing with a subtle status row or section state,
- remove the deep-search indicator when all scheduled phases finish or are cancelled,
- if deep search fails after primary results are already available, keep the valid primary results visible.

A deep-search failure should not replace successful primary results with a global error message.

### 9.5 Result grouping versus global ordering

The implementation may use either:

1. grouped sections such as Galleries, Tags/related galleries, Photos, or
2. one globally sorted list with stable relevance values.

For the first release, grouped presentation is preferred if it reduces visual movement while results are arriving.

A practical layout can preserve gallery results near the top while progressively filling a photo section beneath them.

If the current compact single-list visual design is retained, the merge algorithm must ensure high-confidence gallery results do not jump unpredictably as deep image matches arrive.

## 10. Relevance model

Relevance must be explicit and testable.

The current score values are local to two separate SQL queries and are only merged after both queries finish. Progressive search needs a cross-phase ranking policy.

The exact integer constants may be tuned, but the ordering semantics should start from the following hierarchy.

### 10.1 Proposed relevance classes

Highest confidence:

- exact gallery title
- normalized exact gallery title
- gallery title prefix
- exact gallery tag
- gallery tag prefix

High confidence:

- gallery title substring
- exact image title
- exact filename
- exact image tag
- image title/filename prefix

Medium confidence:

- gallery tag substring
- image tag prefix/substring
- image title/filename substring
- translated title match

Lower confidence:

- gallery description
- translated description
- image description
- tag description
- AI searchable metadata

### 10.2 Initial numeric scoring proposal

The implementation may use these as starting values, centralized in one ranking model rather than scattering magic values across SQL statements:

| Match source | Initial score |
| --- | ---: |
| gallery title exact | 1200 |
| gallery title normalized exact | 1180 |
| gallery title prefix | 1100 |
| gallery tag exact | 1050 |
| gallery tag prefix | 1000 |
| gallery title substring | 950 |
| image title/filename exact | 900 |
| image tag exact | 860 |
| image title/filename prefix | 820 |
| gallery tag substring | 800 |
| image tag prefix | 760 |
| image title/filename substring | 720 |
| image tag substring | 680 |
| translated title match | 640 |
| gallery description | 520 |
| translated description | 460 |
| image description | 420 |
| tag description | 360 |
| AI searchable metadata | 300 |

These numbers are not UI-visible product values. They are an engineering baseline used to make precedence deterministic.

### 10.3 Score combination policy

Avoid unbounded score inflation simply because one entity matches the same query in many low-quality fields.

Preferred policy:

- use the strongest match as the dominant score,
- optionally add small bounded bonuses for additional independent matches,
- cap bonuses so a weak deep match in many fields cannot outrank an exact gallery-title match.

Example principle:

- strongest match score: 950
- secondary matching tag bonus: +20
- matching description bonus: +10
- maximum supplemental bonus: +50

Do not sum every joined-row match without a cap.

### 10.4 Deterministic tie-breaking

Tie-breaking must be stable.

Recommended order:

1. relevance descending
2. result type priority if needed
3. normalized title ascending
4. stable entity ID ascending

Do not rely on incidental database row order.

## 11. Query normalization

The existing whitespace normalization and 120-character length cap should be retained unless there is a reason to change them.

The redesign should introduce one centralized search normalization function for ranking/index terms, separate from the display query if required.

Possible normalization behavior:

- trim and collapse whitespace,
- Unicode-aware lowercase where available,
- preserve the original query for display,
- optionally normalize common separators for technical identifiers,
- do not silently remove characters in a way that changes security or authorization behavior.

### 11.1 Technical identifier expansion

A later stage should support derived search tokens useful for this gallery's real content.

Examples:

- `A320` -> `a320`, `320`, optionally `a 320`
- `A320neo` -> `a320neo`, `a320`, `320`, `320neo`
- `A350` -> `a350`, `350`
- `B737` -> `b737`, `737`
- `C172` -> `c172`, `172`
- `OK-ERO` -> `ok-ero`, `okero`, `ok`, `ero`

This expansion belongs in a reusable normalization/index layer, not in one-off hard-coded aviation conditions.

The algorithm should be generic for alpha-numeric boundaries and separators so it also works for non-aviation content.

## 12. SQL redesign before adding a search index

The first performance improvements should come from query shape.

### 12.1 Candidate discovery first

Each phase should first identify a small ordered candidate ID set using only tables required for eligibility and scoring.

Then a second query should hydrate display details for those IDs.

For example conceptually:

1. find gallery IDs and scores, limit to a small candidate count,
2. fetch gallery rows and only necessary gallery-tag summary data for those IDs,
3. construct public result models.

The same principle applies to images.

### 12.2 Avoid broad join multiplication

Prefer `EXISTS` or narrow subqueries when the question is "does this entity have at least one matching tag?".

For candidate discovery, `EXISTS` can stop after a match and avoids generating every tag combination merely to prove eligibility.

Do not join both gallery tags and image tags into one broad discovery row set unless a measured query plan shows that it is beneficial.

### 12.3 Remove unused aggregate work

Any aggregate selected only because the old query once needed it must be removed if the result renderer does not use it.

The currently selected gallery `COUNT(DISTINCT public_image.id) AS image_count` is one known example that should be reviewed because the current public search result construction does not use it.

### 12.4 Hydrate only visible result details

`GROUP_CONCAT` of tag names should execute only for the small number of gallery/image IDs that will actually be returned.

It must not be part of broad candidate scanning.

### 12.5 Parent gallery criteria in image search

Ordinary image eligibility must not include broad parent gallery matches such as:

- parent gallery title contains query,
- parent gallery description contains query,
- gallery tag contains query.

Those belong to gallery discovery.

Parent gallery authorization/listing state must still be enforced for every image result.

### 12.6 Query limit semantics

The database should apply a bounded candidate limit before detail hydration.

The candidate limit can be modestly larger than the final visible limit to allow deduplication and final cross-source ranking, for example 2x or 3x, but it must remain bounded.

Avoid retrieving hundreds or thousands of candidates for a UI that shows roughly 14 results.

## 13. Visibility, access, and security requirements

Search must use the existing canonical public visibility and listing policies.

Do not duplicate visibility logic in new SQL fragments when existing helpers already define it.

Requirements:

- unpublished galleries must not leak,
- private galleries must not leak,
- unlisted/access-controlled behavior must remain consistent with current public listing/direct-access policy,
- non-public images must not leak,
- context-only search must remain restricted to the allowed gallery branch,
- no phase may use a weaker authorization predicate than another phase,
- search index rows, if introduced, must not become an authorization source of truth.

If a search index says an entity is searchable but canonical gallery/image state says it is not, canonical authorization wins.

## 14. Context-only search

The existing UI can restrict search to the current gallery and its subgalleries.

This must remain functional through every stage.

The current context model uses gallery folder path restrictions. The progressive redesign must preserve equivalent behavior for:

- primary gallery search,
- image search,
- description search,
- AI search,
- future search-index lookup.

No phase may return results outside the requested branch simply because it uses a different optimized query.

Tests must cover nested subgallery behavior and sibling exclusion.

## 15. Localization requirements

Localization support must be preserved.

Recommended phase allocation:

- localized gallery title may participate relatively early,
- localized image title may participate in the lightweight media phase if query cost is acceptable,
- localized descriptions belong in a later descriptive/deep phase.

The browser result title and subtitle must continue to use the active language localization behavior already implemented by the content localization service.

Do not implement a second translation resolution mechanism inside search.

## 16. AI metadata requirements

AI metadata search remains conditional on the canonical `ai_image_metadata` capability and schema readiness.

It must be a late deep-search source by default.

Disabling AI metadata must preserve stored rows and must also remove AI metadata from search ranking and queries, matching the existing capability contract.

AI search should be independently measurable because it may dominate latency on a large metadata corpus.

## 17. Smart Gallery compatibility

The existing search endpoint can return an admin-only action to save the current search as a Smart Gallery.

Progressive search must preserve this workflow.

Preferred behavior:

- the action is associated with the normalized current query, not with one phase,
- the browser renders it once,
- later phase responses do not duplicate the action,
- stale responses cannot replace the action URL with an older query.

If Smart Gallery search rules currently assume the old monolithic search semantics, that must be audited before changing any rule interpretation. Do not silently change saved Smart Gallery meaning as a side effect of public search performance work.

## 18. Failure handling

Each phase must fail independently where possible.

Examples:

- Phase A succeeds, deep AI phase fails: keep Phase A results visible and log the deep failure appropriately.
- Phase A fails completely: show the current search error behavior.
- Phase B times out: keep galleries visible and mark photo expansion as unavailable only if a user-visible message is warranted.
- query changes while a phase is running: silently discard/abort it.

Do not turn a late optional enrichment failure into a total search outage.

Server-side exceptions must continue to be logged through the existing logging infrastructure without exposing raw exception text to public users.

## 19. Observability and performance measurement

Performance work must be measurement-driven.

Before changing query structure, capture a baseline using representative queries.

At minimum include query classes such as:

- exact/obvious gallery identifier: `320`
- common tag/word: `letadlo`
- filename-like search
- image title search
- description-only term
- AI-only term if AI metadata is populated
- a no-result term
- a two-character query at the current minimum length
- context-only equivalents for selected cases

### 19.1 Metrics to capture during development

For each phase measure:

- wall-clock duration,
- candidate count examined/returned where available,
- final result count,
- whether temporary tables/filesort appear in the plan,
- rows examined estimates from `EXPLAIN` or `EXPLAIN ANALYZE` when supported by the target MariaDB version,
- AI on versus AI off,
- cold versus warm cache behavior where practical.

Do not expose these diagnostics to anonymous public clients by default.

Development diagnostics may be written to bounded logs or an explicit admin/debug path if consistent with project policy.

### 19.2 Target UX metrics

Initial targets for production-like data should be treated as goals, not promises:

- Phase A first useful response ideally under 150 ms server time on a warm database for ordinary queries,
- visible browser result ideally well below 300 ms after the input debounce,
- Phase B ideally completes within several hundred milliseconds,
- deep search may take longer but must not block the initial result.

If shared-hosting variability prevents these exact numbers, the architectural requirement remains: Phase A must be materially faster than the current monolithic search and must be independently visible.

## 20. Search result limits

The current public endpoint requests 14 final results.

Progressive search should retain a similar compact UI rather than allowing every phase to add 14 items indefinitely.

Define bounded per-type/per-phase limits.

A reasonable first policy may be:

- up to 6 to 8 gallery results,
- up to 6 to 8 photo results,
- total visible compact results near the current 14-item behavior,
- later phases replace/improve lower-ranked entries rather than endlessly expanding the result box.

Exact counts should be tuned against the existing UI.

## 21. Progressive merge policy

The browser merge algorithm must be deterministic.

Recommended model:

1. store results in a map keyed by stable result ID,
2. replace an existing entry only when the new representation has equal or better rank/detail quality,
3. sort from one centralized comparator,
4. enforce category and total limits after sorting,
5. render from the normalized in-memory state.

Do not append raw HTML fragments from phases in arrival order.

Arrival order is not relevance.

## 22. Visual behavior

The redesign should be visually subtle and consistent with the existing gallery UI.

Recommended states:

- initial searching state before the first response,
- populated state with gallery results,
- lightweight "searching photos" or "searching deeper" indicator while later phases are active,
- final state with no loading indicator,
- partial failure state that preserves valid results.

A small fade/insert transition may be used when new result rows appear, but it must:

- not cause layout thrashing,
- not continuously reorder the list with distracting animation,
- respect `prefers-reduced-motion`,
- not delay clickability.

The search input must remain responsive throughout.

## 23. Accessibility

Progressive updates must remain accessible.

Requirements:

- do not steal focus when results update,
- preserve keyboard behavior including Escape and clear button,
- loading/status text should be available to assistive technology without becoming excessively noisy,
- consider an appropriate `aria-live` strategy for summary status rather than announcing every individual appended result,
- links remain normal navigable anchors,
- reduced-motion preference must be respected.

## 24. Search index architecture for a later stage

After the query/UX redesign is stable and measured, introduce a denormalized rebuildable search index only if useful.

### 24.1 Source-of-truth rule

Canonical tables remain authoritative:

- galleries
- images
- tags and relation tables
- translations
- AI metadata

The search index is derived data only.

It must be safe to delete and rebuild.

### 24.2 Possible document model

A future search document may contain fields conceptually similar to:

- entity type
- entity ID
- gallery ID
- branch/path discriminator needed for context search
- current searchable/public state snapshot
- display/normalized title
- normalized identifier tokens
- tag text
- description text
- AI search text
- language or localized document rows where appropriate
- source update fingerprint/timestamp

The exact schema must be designed from measured query needs. Do not create one giant unbounded text blob if separate columns provide better ranking/control.

### 24.3 Update behavior

Index updates must be driven by existing canonical mutation flows.

Changes that may require reindexing include:

- gallery title/description/slug update,
- gallery visibility/listing/access state update,
- gallery tag changes,
- image title/description/filename/visibility changes,
- image tag changes,
- translation changes,
- AI metadata changes,
- image move between galleries,
- gallery hierarchy/path changes,
- entity deletion/restoration where applicable.

Do not create scattered workflow-specific indexing code if one centralized search-index synchronization service can own this responsibility.

### 24.4 Rebuild capability

A full rebuild mechanism is required before the index becomes relied upon.

It must be resumable or bounded enough for shared hosting if the dataset is large.

The design should consider:

- chunked processing,
- progress state,
- safe restart,
- no requirement for one long HTTP request,
- admin-visible health/status if the index becomes a production dependency,
- preserving search availability during rebuild where practical.

### 24.5 Degraded behavior

If the search index is absent, stale, or rebuilding, the application must have a defined fallback.

The fallback may use the optimized direct SQL phases.

Search index failure must not corrupt canonical data.

## 25. FULLTEXT option

MariaDB FULLTEXT may be evaluated after the denormalized index exists.

It is likely more useful on a dedicated search document than spread across many canonical tables.

FULLTEXT is attractive for:

- longer descriptions,
- tags/words,
- AI text,
- natural-language queries.

However, standard tokenization does not automatically preserve arbitrary substring semantics such as query `320` matching token `A320`.

Therefore FULLTEXT must be combined with normalization/derived identifier tokens or retained substring fallback behavior.

Do not replace known-good technical identifier matching with FULLTEXT without regression tests.

## 26. N-gram/trigram option

If fast arbitrary substring search is still required after progressive search and FULLTEXT work, evaluate a small n-gram index.

For a token such as `AIRBUSA320`, trigrams include examples such as:

- `air`
- `irb`
- `rbu`
- `bus`
- `usa`
- `sa3`
- `a32`
- `320`

A query `320` can then use an indexed equality lookup against the trigram table rather than scanning `%320%` through large text columns.

Tradeoffs:

- larger derived index,
- more write/rebuild work,
- more implementation complexity,
- very strong substring lookup performance.

This is a later optimization only. Do not implement it before profiling the simpler redesign.

## 27. Caching

Caching is a complement, not a replacement for query optimization.

Possible later cache key inputs:

- normalized query
- active language
- context gallery/branch
- phase
- relevant feature/capability state
- search index generation/version if applicable

Cache entries must be invalidated or naturally expire when source content changes.

A short TTL may be sufficient for anonymous public search and avoids building a complex invalidation system prematurely.

Do not cache authorization-sensitive private data into a public search response.

## 28. Feature policy and configuration

Reuse the existing `public_search` capability and public search enabled setting.

Do not create separate feature flags such as:

- `progressive_search_enabled`
- `deep_search_enabled`
- `gallery_search_v2_enabled`

unless a concrete rollout requirement later proves one is necessary.

Internal tuning constants may exist in a centralized search policy/config structure where appropriate.

If a production rollout kill switch is needed temporarily, prefer one documented migration/rollout mechanism over permanent feature-flag proliferation.

AI search must continue to honor the existing `ai_image_metadata` capability.

## 29. Service architecture

Avoid turning `public_search.php` into one large collection of unrelated SQL strings.

During the staged refactor, introduce clear responsibilities while respecting the project's no-Composer structure and existing service conventions.

Possible internal responsibilities:

- query normalization
- search context/policy
- phase orchestration
- gallery candidate discovery
- image candidate discovery
- result hydration
- relevance scoring
- optional search-index synchronization/querying

Before creating new generic helpers, search the codebase for existing database, visibility, localization, feature policy, and URL helpers and reuse them.

Do not duplicate canonical access checks.

## 30. Stage plan

The implementation must proceed incrementally. Each stage should be independently reviewable and testable.

### Stage 0 - Baseline instrumentation and query-plan audit

Goal: establish evidence before changing behavior.

Tasks:

- document representative production-like queries,
- capture current total search latency,
- separately time current gallery and image search functions in a development/profiling mode,
- collect `EXPLAIN` and, where supported and safe, `EXPLAIN ANALYZE` for representative queries,
- compare AI metadata enabled/disabled,
- record approximate row counts for galleries, images, tags, relation tables, translations, and AI metadata,
- identify temporary table/filesort behavior,
- confirm MariaDB version and FULLTEXT capabilities on the target host,
- verify that instrumentation itself is not exposed publicly.

No user-visible search behavior should change in this stage.

Deliverable: baseline measurements and tests/diagnostic support needed for later stages.

### Stage 1 - Search domain contracts and reusable relevance policy

Goal: prepare the fundamental architecture before changing the UI.

Tasks:

- define phase identifiers and response contracts,
- define stable result IDs,
- centralize relevance classes/weights,
- centralize query normalization required by the new code,
- define a candidate model separate from a hydrated result model,
- preserve old `public_search_results()` behavior through composition where practical,
- add unit/model tests for ranking and deterministic tie-breaking,
- add tests for visibility/context constraints.

This stage should avoid broad SQL rewrites if possible. It creates the contracts the next stages use.

### Stage 2 - Fast Phase A gallery/title/tag search

Goal: make obvious gallery searches fast independently of images.

Tasks:

- implement gallery candidate discovery without joining all gallery images,
- support exact/prefix/substring gallery title ranking,
- support gallery tag exact/prefix/substring ranking,
- optionally include localized gallery title according to measured cost,
- hydrate only selected gallery IDs,
- remove any unused image-count aggregation from the fast path,
- add tests demonstrating `320` -> `A320` behavior,
- preserve canonical public-listing and context-only rules.

At the end of this stage the server must be capable of producing a useful primary result set without executing image/deep search.

### Stage 3 - Progressive browser orchestration

Goal: display Phase A before the entire search completes.

Tasks:

- convert browser search state to a query generation/session model,
- support multiple scheduled phase requests,
- own multiple abort controllers/timers safely,
- discard stale generation responses,
- progressively merge/deduplicate results,
- preserve clear/Escape/context-checkbox behavior,
- preserve Smart Gallery action behavior,
- implement non-blocking phase loading state,
- add browser tests for out-of-order responses and stale suppression.

At the end of this stage the user should experience materially faster time to first useful result even if later server queries are still old/slow.

### Stage 4 - Lightweight Phase B image search

Goal: provide direct image matches without deep metadata scans.

Tasks:

- implement candidate-first image search for filename/title/image tags,
- remove parent gallery title/description/tag as broad image eligibility predicates,
- retain parent gallery authorization and URL/display context,
- hydrate only selected image IDs,
- use `EXISTS`/narrow relation lookup where it reduces join multiplication,
- preserve localization for image titles if included in this phase,
- measure before/after plans.

Regression requirement: queries that previously found a gallery because of its title still find the gallery, even though they no longer manufacture photo matches from every image in that gallery.

### Stage 5 - Descriptive and deep Phase C/D split

Goal: preserve broad search quality without blocking primary results.

Tasks:

- move gallery/image description matching to deferred phases,
- move AI searchable metadata to the deep phase,
- move expensive localized descriptions to the deep phase,
- independently handle deep-phase errors,
- tune deep scheduling delay,
- ensure query changes prevent unnecessary deep work where possible,
- add deep-result regression fixtures.

### Stage 6 - Query-shape cleanup and detail hydration optimization

Goal: eliminate remaining unnecessary aggregation and join work.

Tasks:

- audit every `GROUP_CONCAT`, `COUNT(DISTINCT ...)`, and broad `GROUP BY` in the search path,
- ensure aggregation runs only for bounded selected IDs,
- evaluate `EXISTS` against join-based candidate discovery,
- add only indexes justified by query plans,
- validate current relationship indexes are being used,
- compare cold/warm performance.

This stage may overlap with Stages 2, 4, and 5 during implementation, but its acceptance criteria should remain explicit.

### Stage 7 - UX refinement and relevance tuning

Goal: make progressive results stable and understandable.

Tasks:

- tune relevance weights using real queries,
- tune category/result limits,
- decide grouped versus globally ranked presentation based on observed behavior,
- add subtle progressive-result animation if useful,
- support reduced motion,
- prevent visual jumping,
- tune status text and accessibility announcements,
- validate mobile behavior.

### Stage 8 - Optional denormalized search index foundation

Goal: prepare a derived search storage layer if direct SQL remains expensive.

Tasks:

- design migration/schema,
- implement centralized index document builder,
- implement source fingerprint/version policy,
- implement bounded rebuild workflow,
- implement mutation-driven synchronization,
- define degraded fallback to direct optimized SQL,
- add health/consistency checks,
- do not make the index the source of truth.

The stage must be optional based on measurements from previous stages.

### Stage 9 - Optional FULLTEXT and technical token normalization

Goal: accelerate word/description/AI search while preserving identifiers such as `A320`/`320`.

Tasks:

- generate generic derived technical tokens,
- benchmark MariaDB FULLTEXT against current deep search,
- define stopword/min-token behavior relevant to the actual MariaDB configuration,
- verify Czech, English, German, and Swedish content behavior as applicable,
- retain a safe fallback for unsupported substring cases,
- add regression cases for registrations and aircraft-style identifiers.

### Stage 10 - Optional n-gram substring index

Goal: optimize arbitrary substring matching only if it remains a measured bottleneck.

Tasks:

- define minimum query length for n-gram usage,
- design bounded gram storage,
- implement rebuild/update logic through the centralized index service,
- benchmark storage growth and lookup latency,
- keep fallback behavior for short queries,
- add integrity tests.

Do not implement this stage merely because it is technically possible.

## 31. Test plan

Search changes require both correctness and performance-oriented tests.

### 31.1 PHP/model tests

Add dedicated tests for:

- query normalization,
- stable result IDs,
- exact/prefix/substring ranking precedence,
- bounded supplemental score bonuses,
- deterministic tie-breaking,
- gallery visibility exclusion,
- image visibility exclusion,
- context-only branch restriction,
- gallery tag matching,
- image tag matching,
- parent gallery match no longer causing every child image to match,
- localization behavior,
- AI capability disabled behavior,
- Smart Gallery action compatibility,
- search-index fallback behavior if later introduced.

### 31.2 Browser tests

Add tests for:

- Phase A rendering before later phases,
- later photo result merge,
- duplicate suppression,
- old generation response arriving after new query and being ignored,
- abort behavior,
- pending deep phase cancelled before dispatch when user continues typing,
- deep phase failure while primary results remain visible,
- clear button and Escape,
- context checkbox causing a new generation,
- focus behavior,
- reduced-motion behavior if animation is introduced.

### 31.3 SQL/schema tests

Where the project test infrastructure supports it, test:

- migration safety for any new indexes/tables,
- MySQL/MariaDB compatibility,
- index existence only where required,
- search index rebuild idempotence,
- cascade/delete behavior for derived index rows if introduced.

### 31.4 Performance fixtures

A synthetic large-data test or benchmark helper should be considered.

It does not need to reproduce 130 GB of media because media binaries are irrelevant to metadata search. It should instead create realistic counts and relationships for:

- many images,
- multiple image tags,
- multiple gallery tags,
- descriptions,
- translations,
- AI metadata rows.

The benchmark should specifically reproduce join multiplication patterns that hurt the current implementation.

## 32. Acceptance scenarios

### Scenario A - `320`

Given a public listed gallery named `A320`:

- Phase A returns `A320` quickly.
- The browser displays it without waiting for image description or AI search.
- Images in the A320 gallery do not automatically become photo matches only because their parent gallery title contains `320`.
- A specific image whose own title/filename/tag contains `320` may appear in Phase B.
- A specific image whose description/AI metadata contains `320` may appear later in deep results.

### Scenario B - `letadlo`

Given no exact gallery title but one or more gallery tags matching `letadlo`:

- Phase A returns relevant tagged galleries.
- Those galleries are visible while image search continues.
- image title/tag matches can appear later.
- description/AI-only matches arrive last.

### Scenario C - description-only term

Given a term found only in one image description:

- Phase A may return no results.
- UI continues to indicate deeper search rather than declaring final no-results too early.
- deep phase eventually returns the image.
- only after all scheduled phases return no matches may the UI show the final empty state.

### Scenario D - rapid typing

User types `32`, pauses briefly, then types `320`:

- any scheduled deep search for `32` is cancelled before dispatch if possible,
- active `32` requests are aborted in the browser,
- a late `32` response cannot mutate the `320` result set,
- `A320` result appears from the active generation.

### Scenario E - deep failure

Phase A returns galleries successfully but AI/deep search throws an exception:

- gallery results remain visible,
- the entire result panel does not become a fatal error state,
- failure is logged through existing bounded logging,
- no exception details are exposed publicly.

### Scenario F - current gallery context

User enables "Search only this gallery and its subgalleries":

- every phase applies the same branch boundary,
- sibling and unrelated galleries/images are excluded,
- changing the checkbox invalidates prior phase results and starts a new generation.

## 33. Migration and rollout safety

The redesign must be deployable in stages.

Preferred compatibility approach:

- keep the existing route,
- introduce internal phase-aware services,
- keep a legacy complete-response composition during transition,
- move the browser to progressive mode only after phase endpoints are tested,
- remove or simplify legacy monolithic query code only after equivalent coverage exists.

Do not perform a one-commit replacement of backend, browser, ranking, schema, and indexing.

If a stage changes updater-managed files, regenerate and verify `app/core-manifest.json` according to project rules before creating affected ZIPs.

## 34. Documentation requirements during implementation

When the redesign is implemented, update the project's canonical documentation where appropriate:

- `ARCHITECTURE.md` for final search architecture and data flow,
- `DATABASE.md` for any schema/index/search-document changes,
- `CODEMAP.md` for new services/modules,
- `TESTING.md` for new regression/performance test coverage,
- `PATCH_NOTES.md` only according to the project's release-note process.

This TEMP specification should remain a working implementation guide until the redesign is complete. Once all accepted stages are implemented and the canonical documentation reflects the final architecture, it can be removed in a dedicated cleanup change.

## 35. Coding and project conventions

Implementation must follow the repository rules in `AGENTS.md`.

Specific requirements:

- preserve existing docblocks/docstrings,
- add docblocks for new project functions according to repository conventions,
- reuse existing helpers before creating similar ones,
- keep canonical feature policy centralized,
- keep canonical public visibility/access logic centralized,
- avoid exposing sensitive diagnostics,
- preserve current URL generation helpers,
- keep translations through existing translation helpers/catalogs,
- run the required manifest generation/check when managed files change,
- run the required project audit/test profiles before handoff.

## 36. Decisions intentionally deferred until measurement

The following must not be decided by guesswork in Stage 0/1:

- whether Phase A title substring should use direct `LIKE '%query%'` permanently,
- whether gallery descriptions are cheap enough for Phase A or should remain deferred,
- whether translated titles belong in primary or secondary search on the production dataset,
- exact debounce/defer intervals,
- exact result limits by category,
- whether grouped UI or globally sorted UI is superior,
- whether FULLTEXT is necessary,
- whether n-gram indexing is necessary,
- whether a denormalized search index is necessary at all after SQL refactoring,
- whether additional simple indexes materially improve actual plans.

Measurements and UX tests should decide these points.

## 37. Recommended first implementation sequence

When implementation begins, use the following immediate sequence:

1. Stage 0 baseline and query-plan capture.
2. Stage 1 contracts, ranking model, and test scaffolding.
3. Stage 2 fast gallery/title/tag backend phase.
4. Stage 3 progressive browser orchestration so the user benefits immediately.
5. Stage 4 lightweight image phase and removal of broad parent-gallery image eligibility.
6. Stage 5 deferred descriptions, localization-heavy paths, and AI metadata.
7. Stage 6 SQL cleanup and measured index tuning.
8. Stage 7 UX/relevance tuning.
9. Re-measure.
10. Only then decide whether Stages 8 to 10 are justified.

This ordering intentionally delivers a visible UX improvement before introducing new derived storage.

## 38. Definition of done for the progressive-search redesign

The core redesign, excluding optional future index/FULLTEXT/n-gram stages, is complete when all of the following are true:

- obvious gallery/title/tag results can be returned without scanning image descriptions or AI metadata,
- the browser displays primary results before deep search finishes,
- stale/out-of-order phase responses are impossible to render into the active query,
- parent gallery title/tag matches do not automatically make every child image a photo result,
- image title/filename/tag search remains available,
- description, localization, and AI metadata search remain available as deferred enrichment,
- public visibility/access semantics are unchanged,
- context-only search works across all phases,
- Smart Gallery save action remains correct,
- relevance is centralized and covered by tests,
- broad candidate discovery no longer performs unnecessary display aggregation,
- representative search latency is measured before and after the change,
- all relevant PHP and browser regression tests pass,
- project audit requirements pass or any environment-only blocked checks are explicitly documented,
- canonical architecture/database/testing documentation is updated.

## 39. Expected outcome

The redesign should make a large gallery feel fast even when exhaustive metadata search is inherently expensive.

For a query such as `320`, the system should not wait for image tags, descriptions, translations, AI metadata, and aggregation across large image sets before showing the obvious `A320` gallery.

For a query such as `letadlo`, gallery tags should provide early useful navigation results while image-level and descriptive search continues.

The final objective is not merely a lower total SQL duration. The primary objective is a lower time to useful information, combined with lower unnecessary database work and preserved broad search quality.
