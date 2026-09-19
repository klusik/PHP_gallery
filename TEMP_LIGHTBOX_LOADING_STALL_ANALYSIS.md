# Lightbox Loading Stall Analysis

## 1. Executive finding

The strongest static finding is an incomplete navigation transaction lifecycle in `public/assets/gallery-modules/lightbox.js`: current presentation attempts can resolve `false` without terminating their loading UI. `openAt()` only performs final cleanup when `wasDisplayed` is true (approximately 4615–4625). A failed presentation is therefore allowed to become a permanently busy viewer rather than a completed failure.

There is also a concrete ownership hole before that lifecycle: navigation to a missing metadata card changes `currentIndex` and returns before invalidating image/quality tokens or clearing the previous loaders (4462–4472). A failed metadata request then returns silently. Existing operations can become stale without their owner being retired.

Confidence that these are code defects: HIGH. Confidence that either specifically triggered the reported incident: MEDIUM; the snapshot cannot identify the active request or presentation outcome.

The navigation indicator already has `pendingLightboxNavigationToken`; this is not simply a missing-token implementation. Its cleanup coverage is incomplete. Quality and initial loading use shared DOM state, with ownership enforced only by callers.

Back → Forward reinitializes these states and retries presentation, which fits these defects better than a permanently poisoned unresolved cache entry. No evidence establishes that a second `decode()` itself hangs. Preserve the current clone/geometry pipeline.

Scope: current local source only; static inspection. No tests, audits, runtimes, browser, downloads, archives, or application edits.

## 2. Actual current pipeline

All JavaScript symbols below are in `public/assets/gallery-modules/lightbox.js` unless otherwise qualified.

1. `public/assets/public-gallery.js::bootPublicGalleryBrowserFeatures()` calls the deferred `setupGalleryLightbox()`. `lightbox-deferred.js::activateFullLightbox(setupToken)` imports once, checks the page setup token, installs the real viewer, and replays the triggering click. This is initialization, not a per-arrow loading path.
2. Click/keyboard → `step(offset)` → `openAt(index, options)` (4431). Reset zoom, calculate target, possibly `showInitialLightboxLoader()`.
3. Missing card: change `currentIndex`; await `fetchLightboxWindowAround()` → `fetchLightboxRange()` → JSON → `mergeLightboxItems()` → `createLightboxCardFromItem()`; recursively call `openAt()` on success. This branch precedes normal token renewal.
4. Available card: set `currentIndex`; increment `activeLightboxImageToken` and `activeLightboxTransitionToken`; `clearPendingLightboxQualityUpgrade()` aborts quality, increments its token, clears its source and UI; remove transition; `resetLightboxPreloadQueue({abortActive:false})`; sweep cache.
5. `scheduleLightboxNavigationPending(index, token, previewSrc || mainSrc)` replaces the navigation owner and arms a 140 ms timer. Current source preloading is started synchronously by `preloadCardLightboxImages(card, false)`.
6. `showLightboxImageSource()` → `loadDecodedLightboxImage()` → cached promise or `loadFreshDecodedLightboxImage()` → detached `Image.onload` → `decodeLoadedImage()` → detached promise resolves. Preview navigation has no byte-progress stream.
7. Validate request and geometry; choose immediate `commitPreparedLightboxImage()`, same-source success, or `showPreparedLightboxTransitionImage()`. Transition: clone decode → two animation frames → fade timer → commit. Commit: display clone decode → validate old-node identity/current request/image dimensions → replace live node → two animation frames → validate and clear navigation pending.
8. Failed preview presentation returns `false`; `openAt()` tries `mainSrc`. Successful final presentation clears navigation and initial loaders and schedules quality/slideshow. Final `false` currently has no terminal UI handling.
9. `scheduleLightboxQualityUpgrade()` → `promoteLightboxQualityIfNeeded()` → `loadTrackedDecodedLightboxImage()` → `primeLightboxImageCacheWithProgress()` fetch/body reads → fresh detached Image/decode → `installDecodedLightboxQualityImage()` → one animation frame → quality cleanup. Resize/reclamp can also initiate this evaluation independently of navigation completion.
10. `preloadAdjacentImages()` actually runs synchronously near the end of `openAt()`, before foreground completion. Neighbor work uses the idle queue. Fullscreen reuses this same image, state and pipeline.

## 3. State ownership table

| State / token / flag | Owner | Set by | Cleared by | Can become stale? | Risk |
|---|---|---|---|---|---|
| `currentIndex`, `activeLightboxImageToken` | Viewer navigation | `openAt()` | Increment on available-card navigation, close, teardown | Yes; missing-card branch changes only index | Staleness without retiring old operation |
| `pendingLightboxNavigationToken`, `pendingLightboxNavigationTimer` | Latest scheduled navigation | `scheduleLightboxNavigationPending()` | `clearLightboxNavigationPending(token)` or unconditional reset | Yes | False presentation can retain owner/class after timer has fired |
| `is-navigation-loading` | Overlay; caller-managed ownership | 140 ms pending callback | Navigation clear helper | Yes | No independent terminal-state reconciliation |
| `initialLightboxLoadActive`, `is-initial-loading` | Viewer, no generation field | `showInitialLightboxLoader()` | `hideInitialLightboxLoader()`, close | Yes | Metadata/final display failure can leave image hidden and gestures blocked |
| `activeLightboxTransitionToken`, `transitionImage` | Presentation attempt | Transition setup; navigation; explicit zoom | Token invalidation, `removeTransitionImage(node)` | Yes | Cancellation resolves false; not equivalent to finishing navigation |
| `activeLightboxQualityRequestToken`, abort controller, `pendingLightboxQualitySource` | Quality request | Passive/explicit quality entry | Quality completion/catch or `clearPendingLightboxQualityUpgrade()` | Yes | First stale-success guard skips cleanup; missing-card branch does not invalidate quality |
| `is-quality-loading`, progress text/`hidden` | Shared progress DOM | Quality setters, unguarded progress callback | Quality setters with token checks at some callers | Yes | Two loading classes independently keep panel visible |
| `image`, `activeLightboxQualitySource` | Live presentation | Source apply / quality installation | Navigation clears quality source; close removes image source | Yes | Node identity can change during pending display clone decode/frame check |
| `decodedLightboxImages` entry promise/`settled` | URL cache | Remember helper | Settlement, eviction; close clears | Survives navigation intentionally | Never-settling entry is retained and reused |
| `lightboxPreloadGeneration`, queue, active count | Background scheduler | Queue/reset/drain | Queue reset; per-operation `finally` decrements | Active work survives generation changes | Count remains occupied if underlying promise never settles |
| `lightboxMetadataGeneration`, `lightboxPendingWindows` | Viewer metadata lifecycle | Range fetch | Cancel lifecycle; identity-checked `finally` | Navigation callbacks only check index | Same-index revisits can admit older metadata continuations |
| `galleryDevModeState.currentSource/Kind`, `sourceStats` | Historical diagnostics | Apply/install; source event writers | Close resets current source; new writes replace statuses | Yes | Displayed source history is not active-navigation readiness |

## 4. Race analysis

### A. Current presentation terminates unsuccessfully, navigation remains busy

Functions: `showLightboxImageSource()`, `commitPreparedLightboxImage()`, `showPreparedLightboxTransitionImage()`, `openAt()`.

Sequence: navigation B owns `pendingLightboxNavigationToken`; its timer shows the panel. Detached B loads and is marked ready. Presentation can return false for invalid clone dimensions/completeness, changed `image !== previousImage`, lost transition ownership, or failed post-frame identity. These are resolved failures, not rejected download promises. Preview failure falls back to main; if the final presentation also returns false, `openAt()` immediately returns without clearing B's pending owner. No further callback is scheduled to settle B.

This proves a terminal-cleanup hole, not that each false-producing condition occurred in the reported run. One failed preview alone is insufficient: main presentation can recover. In particular, clone decode rejection is swallowed and followed by a completeness check; rejection alone does not prove an unresolved promise.

Back → Forward replaces the pending owner, clears the class, removes the transition and retries with a newly created display node. A cached detached source can now succeed without further transport.

### B. Missing metadata target strands the previous owner

Functions: `openAt()` missing-card branch, `fetchLightboxRange()`, `promoteLightboxQualityIfNeeded()` / `requestLightboxQualityUpgradeNow()`.

Sequence: A is navigation-loading or quality-loading. Step to uncached card B changes `currentIndex` but does not increment image/quality tokens, abort quality, clear navigation pending, or remove A's transition. A finishes; its request-validity guard rejects its now-wrong index. Navigation presentation skips clearing A; quality's first success guard returns false before clearing `pendingLightboxQualitySource`, abort-controller reference and quality UI. If B metadata returns false, B's continuation also just returns. The panel stays indefinitely although A may already be decoded.

Even successful metadata latency permits temporary desynchronization; a failed/refused request or uncompleted metadata operation makes it persistent. `fetchLightboxRange()` has a correctly identity-checked pending-map `finally`, but that releases deduplication, not loading UI. Successful payloads missing the requested card can repeatedly re-enter `openAt()` without a bounded terminal failure.

Back to a loaded card executes the skipped reset block; Forward retries metadata after the pending entry settles. An index-only callback also admits multiple continuations for A → B → C → B while one B metadata promise is shared; each may reopen B and supersede the other. This normally causes churn, not a permanent stall by itself.

### C. Quality changes live-node ownership during presentation

Functions: `scheduleLightboxZoomReclamp()`, resize listener (~5281), `scheduleLightboxQualityUpgrade()`, `commitPreparedLightboxImage()`, `installDecodedLightboxQualityImage()`.

After a preview node is installed but before its two-frame acknowledgement, a separately scheduled quality completion can replace `image`. Commit then resolves false despite a valid full-quality image being connected. During a same-image revisit, matching `data-lightbox-image-id` also permits quality evaluation while another display clone is being prepared. Explicit zoom additionally increments `activeLightboxTransitionToken` and removes the transition before its full-source deduplication return.

This is a real independent ownership interaction, but ordinary preview failure has a main-source fallback. It is contributory evidence for A, not proof that one quality completion alone strands every navigation. Recovery navigation retires quality, clears transition state and creates a fresh presentation attempt. Preserve quality promotion; make its relationship to navigation completion explicit.

### Async boundary accounting

| Boundary | Navigation effect / cancellation | Settlement and shared-state consequences |
|---|---|---|
| Metadata fetch + JSON | Lifecycle abort, not per-step cancellation; continuation index guard | Cache merge allowed within lifecycle; false has no UI terminal action |
| Navigation 140 ms timeout | Replaced/cancelled by next scheduled owner | Current index/token guard prevents ordinary old show; clear is token-checked |
| Detached load/error/decode | Normal steps leave loads running; close/teardown cancels registry; preload/quality signals may abort | `settled` plus cleanup balances load/error/abort; stale loads still legitimately populate cache/DEV |
| Fetch/body stream | Quality controller aborts on ordinary navigation | Reader lock released in `finally`; progress callback lacks quality token check; no application deadline |
| Clone decode | Not abortable through existing navigation signal | Later guard suppresses stale commit; no settlement deadline |
| Transition frames/timer | Not cancelled; later guards resolve false and remove their own node | No `transitionend` dependency; false does not itself clear loader |
| Commit two frames / quality one frame | Not cancelled; identity guards on completion | Hidden tabs may suspend frames; no evidence for permanent suspension while visible |
| Quality debounce / reclamp frame | Cleared on ordinary navigation; reclamp can reschedule using current state | Independent producer of quality work; missing-card branch skips quality reset |
| Preload idle callback / operation finally | Queue generation replaced; active work retained on steps | Completion decrements regardless of generation; no demonstrated arithmetic leak |
| Slideshow timer / prepared image | Schedule token and stop lifecycle supersede cycle | Uses same presentation path; not required to reproduce arrow-navigation defect |

## 5. Loader UI analysis

`public/assets/styles/lightbox.css:524–547` renders `.lightbox-quality-progress` when either `is-navigation-loading` or `is-quality-loading` is present and initial loading is absent. `[hidden]` overrides display. Both JavaScript setters preserve visibility if the other class remains set. CSS does not clear either class.

All relevant show paths:

- `scheduleLightboxNavigationPending()` timer → `setLightboxNavigationLoading(true)`: inserts the exact `lightbox.image_loading` / “Loading image...” text. Progress is deliberately indeterminate here.
- Passive `promoteLightboxQualityIfNeeded()` and explicit `requestLightboxQualityUpgradeNow()` → `setLightboxQualityLoading(true)`. Byte metrics come from fetch, followed by a distinct Image/decode stage.
- `openAt()` → `showInitialLightboxLoader()`; initial deferred activation → `lightbox-deferred.js::showDeferredLightboxLoader()`. These use the separate initial panel and `is-initial-loading`.

Expected hide paths:

- Navigation: successful commit's two-frame check; same-source success; current rejected load in `showLightboxImageSource().catch`; successful final `openAt()` continuation; next navigation's scheduling reset; close/teardown.
- Quality: reset/abort via `clearPendingLightboxQualityUpgrade()`; installation success; installation false while quality token is still owned; current-token catch.
- Initial: final successful `openAt()`; noninitial `openAt()`; close; deferred activation failure through `hideDeferredLightboxLoader()`.

Asymmetries: resolved presentation `false` bypasses the load catch; final `openAt()` false skips both loaders. An actual load rejection clears navigation but does not hide initial loading. The first quality success guard has no owner-finalization. Missing-card navigation skips the normal reset block. Byte progress writes are not guarded, but cannot themselves set a loading class; they can corrupt labels/metrics, not independently explain permanent visibility.

Ordinary A → B → C with all cards present has an important defense: each scheduling call unconditionally clears the old navigation timer/class and assigns a new owner. A stale clear cannot hide C because its token mismatches. Do not diagnose that protected sequence as an unowned-boolean race.

## 6. Preload/cache analysis

Current code still shares foreground and background promises. `preloadCardLightboxImages(card,false)` starts a low-priority current preview before `showLightboxImageSource()` asks for it; `loadDecodedLightboxImage()` then takes that cache entry. The prior experimental separation is not present in this baseline.

Unsettled entries cannot be evicted by `sweepDecodedLightboxImageCache()` or `trimDecodedLightboxImageCache()`. `loadFreshDecodedLightboxImage()` has no deadline for Image load/decode. A never-settling promise can therefore block its foreground consumer and retain an active preload slot. However, Back → Forward would normally retrieve the same unresolved promise again. Reliable immediate recovery weakens this as the primary explanation.

Resolved-null preload failures are retried with a fresh load. Cache settlement writes use entry identity; aborted preload deletion checks promise identity. Queue active count decrements in `finally` regardless of generation, and reset does not zero active count. No concrete negative/leaked-counter race was found. Current-preview preloads bypass queue concurrency, so rapid navigation can increase detached work, but no reported overload supports that as primary.

Assessment: cache sharing is a possible contributor; permanent queue starvation is unproven. A ready cache entry says nothing about clone commit or loader settlement.

DEV semantics (definitions at ~1416, 1498, 1569, 1583, 1665):

- `idle`, `pre`, `load`, `ready`, `err`: counts of last recorded statuses across **all** `galleryDevModeState.sourceStats` URLs. Idle is registration state, not an idle active transaction. Pre/load are event labels, not scheduler slot counts.
- `devMarkSource()` overwrites status even on cache hits; an already settled source can be relabelled preloading/loading without a new transfer. Counts are not a current network census.
- `preview:ready:decoded`: some preview's detached `onload`/decode continuation ran. `decodeLoadedImage()` swallows decode rejection, so this label does not prove successful decode, valid clone, current token or display.
- `preview:loading:fresh`: creation of some detached preview Image. The event string omits index/token/source URL. Adjacent work can produce either event.
- `src full`: `currentSourceKind` written by source apply/quality install. Ordinary `openAt()` does not reset this diagnostic field, so it can still describe the previous image while `currentIndex` already describes the target.
- `2560x1080`: current card's metadata dimensions; not live image dimensions. `known 122`: source-stat map size. `cache 12/12`: entry count/configured limit, including pending entries. `P4/F0`: configured radii. `devCurrentWindowSummary()` reads the same historical map for nearby card URLs.

Consequently the snapshot is consistent with a presentation/UI stall, but does not prove that image 37's requested source was decoded or committed.

## 7. Presentation/decode analysis

Preserve `createPreparedLightboxDisplayNode()` and existing centered geometry. Cached preview nodes are cloned so display cannot move the cache's node. Quality installation uses a fresh tracked load, clears its handlers, and installs that node directly; it is not a cached preview-node takeover.

`decodeLoadedImage()` catches rejection. A settled rejection can yield a valid complete image or a false completeness check. It is not evidence of a hanging second decode. A browser promise that never settles would block presentation, but static source cannot establish that behavior.

`commitPreparedLightboxImage()` catches errors and returns false. Transition uses a timer, not `transitionend`, so missing CSS transition events are not its completion dependency. Frame callbacks are guarded but not abort-settled. Callback exceptions inside later frame/timer tasks are not automatically caught by the outer promise chain; no specific ordinary throwing expression was established as the incident trigger.

One smaller stale-state issue: `showLightboxImageSource()` computes `prepareDecodedLightboxGeometry(decodedImage)` before checking the image token. That helper resizes the normal stage. Stale loads can therefore alter geometry even when they cannot install pixels. This is an ordering defect, not sufficient alone to explain the permanent panel.

Layer distinction: transport/Image/decode can block before readiness; their noncompletion does not explain a **proven current** successful commit. Historical cache/DEV readiness can coexist with any pending current request. Validity, clone presentation, transition, quality ownership and loader finalization can explain ready resources with unresolved UI. The visible panel has no independent acknowledgement of cache readiness.

## 8. Normal Gallery vs Smart Gallery

Both converge on the shared card attributes and viewer. `app/controllers/public_gallery_lightbox.php::lightbox_image_data_attributes()` prepares shared source/quality attributes. `app/controllers/gallery_lightbox.php::gallery_lightbox_json_item()` prepares `preview_src`, `full_src`, `quality_sources`, index and ID, plus optional physical-source gallery context.

Targeted callers in `app/controllers/smart_galleries.php` use `lightbox_image_data_attributes()` (~834), `render_lightbox()` (~1036), and `gallery_lightbox_json_item()` (~1238). Client `createLightboxCardFromItem()` maps this same payload into shared datasets. Source-gallery attribution and metadata scope differ; presentation and loading ownership do not. No separate Smart Gallery image runtime is needed to explain the symptom.

## 9. Ranked root-cause hypotheses

1. **Current presentation ends false without terminal loading cleanup. Confidence: MEDIUM.**
   Evidence: explicit false-return paths; final `openAt()` only cleans successful display; next navigation restores invariants; consistent with settled resources and zero source errors.
   Contradicting evidence: exact false-producing event is not captured; preview failure normally falls back to main. Code defect is certain, incident attribution is not.

2. **Missing-card navigation invalidates by index without retiring previous loading ownership. Confidence: MEDIUM.**
   Evidence: branch precedes token/reset block; metadata failure is swallowed into false; old quality success can skip cleanup with its quality token still current.
   Contradicting evidence: requires unloaded metadata; successful metadata and subsequent presentation repair the state. No evidence that image 37 was missing a card.

3. **Quality/preview presentation overlap causes current node/transition validation failure. Confidence: LOW.**
   Evidence: independent resize/reclamp quality scheduling; live node replacement; explicit zoom transition invalidation; identity guards resolve false.
   Contradicting evidence: different-image ID guard blocks many overlaps; ordinary navigation resets zoom and quality; main fallback often recovers. Needs hypothesis 1 for lasting UI failure.

4. **Shared unresolved detached-load/decode promise. Confidence: LOW.**
   Evidence: no deadline, foreground cache sharing, unsettled entries retained.
   Contradicting evidence: Back → Forward normally reuses the same pending promise; prior targeted preload experiments failed; no active-source correlation in DEV snapshot.

## 10. Recommended minimal fix

Target `public/assets/gallery-modules/lightbox.js`; retain current image-loading, clone, geometry and transition implementation.

Make every `openAt()` intent own a transaction before the missing-card branch: renew navigation validity, retire prior navigation/quality loading and transition ownership, then perform metadata/preview/main work. Metadata resume must retain/check that intent, rather than accepting only matching index or accidentally creating multiple unrelated transactions.

Add one token-checked terminal completion path for the final preview/main result, including false and rejection. Success preserves current quality/slideshow scheduling. Current failure clears its navigation/initial loading and presents an explicit failure state while retaining recoverable controls/previous pixels. Stale completion must not clear another owner's UI. Merely hiding a loader does not make a failed presentation successful.

Finalize quality-owned pending source/controller/loading in an owner-checked terminal path even when the first success guard rejects installation. Do not use unconditional asynchronous global cleanup. Guard byte-progress callbacks with captured quality/image ownership.

Move the image-request guard ahead of geometry preparation. Do not remove the clone decode or install cached nodes directly, and do not repeat the opening regression. Keep authorized URLs, preview-first behavior, fullscreen, no-JavaScript fallback, zoom geometry, cache limits and neighbor scheduling unchanged. A deployed JavaScript change must update the existing cache-busting import chain; no application changes are made by this report.

Static evidence supports these invariant repairs, not a guarantee that they fix a browser-native never-settling decode. Do not add preload watchdogs or redesign presentation without a captured active transaction showing that remaining failure.

## 11. Implementation plan for the next model

1. `lightbox.js::openAt()` / missing-card continuation: establish intent token and retire previous loading/quality/transition before branching. Carry/check intent across metadata resume. Invariant: every accepted navigation supersedes the old owner, including sparse-card navigation.
2. `lightbox.js::openAt()` final promise: route true, false and rejection through one owner-checked terminal handler after preview/main fallback finishes. Invariant: every settled current transaction exits busy state, including failure; stale completion cannot finish its successor.
3. `lightbox.js::promoteLightboxQualityIfNeeded()` and `requestLightboxQualityUpgradeNow()`: centralize quality-token-checked finalization and wrap progress writes with request ownership. Invariant: stale installation and aborted requests cannot retain owned loading state or overwrite successor metrics.
4. `lightbox.js::showLightboxImageSource()`: reject stale request before `prepareDecodedLightboxGeometry()`. Preserve display clones, decode calls, transitions and dimensional checks. Invariant: stale detached completion cannot mutate current presentation geometry.
5. `lightbox.js` completion/DEV diagnostics: record bounded navigation token, target ID, stage and terminal failure reason at existing lifecycle edges. Invariant: diagnostics distinguish source readiness from current display completion; do not infer success from historical `src full`.
6. Existing `public-gallery.js` → `lightbox-deferred.js` import revision mechanism: bump the revision reaching the edited module when implementing. Add focused ownership/presentation-false and missing-metadata regression coverage under the repository's central audit workflow; refresh managed manifest at implementation handoff. None of these steps were executed here.

## 12. Verification scenarios

Not run. After implementation:

- Rapid Next, rapid Previous, alternating directions, and A → B → C → B; final target settles, old callbacks cannot change loader ownership.
- Cached revisit and forced current presentation `false` after detached readiness; fallback either displays or terminates with failure, never permanent busy.
- Preview → full with resize/fullscreen changes during clone decode and commit frames; successful full display is not incorrectly left navigation-loading.
- Normal and fullscreen navigation in ordinary and Smart Galleries, including a sparse metadata boundary; failed metadata exits busy and later navigation retries safely.
- Close during metadata, detached Image, fetch, decode and transition; reopen before old callbacks complete. Old owners cannot alter the reopened viewer.
- Preserve first-click opening, slideshow handoff, authorized media, zoom geometry and preview fallback; specifically guard against the previous clone/presentation opening regression.
