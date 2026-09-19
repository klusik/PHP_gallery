# Lightbox Stall Investigation - Astra 6 High

## 1. Executive finding

**CONFIRMED current-source defect:** `public/assets/gallery-modules/lightbox.js::evictSettledDecodedLightboxImage()` calls **`lightboxIndexForSource(src)` at line 2289, but that function does not exist in the tracked application and is not imported**. The existing lookup is `devFindSourceIndex(src)` at 1671.

**LIKELY mechanism:** inserting a current preview into a full decoded cache triggers this synchronous ReferenceError **after navigation has armed its loading timer but before it has attached its image-presentation/finalization chain**. The newly started Image continues and can report `preview:ready:decoded`; nobody advances that navigation to presentation. This directly explains `source-selection`, normal network/frame activity, a full 12-entry cache, and intermittent Back → Forward recovery. It also produces a concrete preload-slot leak in another caller.

Confidence is **HIGH in the current-code defect and reachable failure sequence; MEDIUM in attribution of the particular screenshot**. Its approximate `evict 6` conflicts with this exact source: the only eviction-counter increment is after the undefined call. That discrepancy must be resolved, not dismissed. No browser, application, tests, audits, downloads or source edits were used. Static investigation is complete; only this report was created/updated.

Baseline: working-tree source was clean on initial inspection; latest lightbox commit `2e78e1b` (“Navigation fix”). Local `git grep` found the helper name only at the call site. Commit `53522cb` introduced it. No external/global implementation is supplied by the tracked repository.

## 2. Why the previous fix failed

**CONFIRMED:** `beginLightboxNavigationTransaction()` (1427) now renews ownership before sparse metadata work. `finalizeLightboxNavigationTransaction()` (1453), invoked by `openAt()` at 4802–4809, now handles fulfilled true/false and rejected presentation promises. Owner-checked quality finalization/progress and the pre-geometry stale guard are present. The earlier report's missing-card and settled-false omissions have been addressed.

What that fix missed is **synchronous setup failure outside the attached promise chain**:

`openAt()` → arm pending timer → `preloadCardLightboxImages()` → `preloadDecodedLightboxImage()` → start detached Image → `rememberDecodedLightboxImage()` → insert entry → trim → delete victim → undefined helper throws.

Execution never reaches construction of `initialMainPromise`, much less its finalizer. Adding a catch to an expression evaluated later cannot catch this exception. This is not necessarily a promise stuck inside source selection: the navigation's foreground promise may never have been created.

The previous analysis read the eviction/cache logic but did not verify the existence of its source-index helper, nor distinguish synchronous exceptions during cache insertion from pending download/decode work. Its “no concrete counter leak” assessment also missed that `drainLightboxPreloadQueue()` increments a slot before a synchronously throwing call, so its `.finally()` may never attach.

A second distinction matters: a throw from the cache's **settlement observer** rejects that observer's ignored child promise. It does not make the already-settled original image promise pending. Do not describe all these exception contexts as the same deadlock.

## 3. Runtime diagnostic interpretation

**CONFIRMED producers:** `galleryDevModeState` (~854), `markLightboxNavigationDiagnostic()` (1413), `devRegisterSource()` (1604), `devMarkSource()` (1686), `devStatusCounts()` (1757), `devCurrentWindowSummary()` (1771), `renderGalleryDevModeOverlay()` (~1853).

| Displayed field | Actual meaning and limitation |
|---|---|
| `nav t14 target 1793 source-selection` | Token passed to the last stage writer, target card's image ID, and last recorded stage. Assigned at `openAt:4677`. It is not an asynchronous selector's status. |
| Exit from `source-selection` | A current decoded HTMLImageElement reaches `showLightboxImageSource:3132` and marks `presenting`, or terminal finalization marks `failed`. A new intent also changes it. No source-selection timeout exists. |
| `src preview` | `currentSourceKind` from the last `applyLightboxImageSource()` or `installDecodedLightboxQualityImage()`. Begin-navigation clears `activeLightboxQualitySource`, **not** DEV `currentSource/Kind`. It can describe the previous displayed image. |
| `image 14/219`, `2560x1080` | Requested index + 1, cards-array length, and target card's metadata dimensions. Not necessarily the live image ID or decoded dimensions. |
| `load 1` | One URL in `sourceStats` has last status `loading`. **Not one active request, fetch, decode or preload slot.** A settled cache hit is marked `loading:load-hit` and need not later receive a source-ready event if its consumer becomes stale. |
| `idle 100 / pre 12 / ready 10 / err 0` | Counts of last-written URL status labels across the historical map. Preload/cache hits overwrite them; aborted operations need not reset them. Error count here excludes ReferenceErrors and presentation failures. |
| `preview:ready:decoded` | A detached preview's onload/decode continuation reached `devMarkSource()`. Event omits URL/ID/token. Can be target or neighbor. `decodeLoadedImage()` swallows decode rejection, so even this is not a successful-presentation assertion. |
| `preview:loading:fresh` | Some preview's fresh detached Image loader began. No foreground-owner identity in the event. |
| `preview:preloading:preload-miss` | Some preview was absent from the URL cache and preload creation began. A current preview uses this path too. |
| Recent-event order | `devLog()` unshifts newest entries; shown newest-first. The trio ready → fresh → preload-miss is compatible with one completed preload **without a foreground load-hit ever occurring**. This is a particularly good fit for interrupted current-preview insertion. |
| `cache 12/12` | Map entry count and desktop cap. Entries include pending promises. Insert 13 → delete victim → throw leaves exactly 12. |
| `known 123` | Historical URL-stat map size, not resident decoded cache size. |
| `P4/F0` | Configured preview/full preload radii. Actual concurrency/radius may be reduced by connection policy. |
| `preload 17 / load 23 / hit 82 / miss 17` | Cumulative instrumented starts/lookups. Current-preview preload plus foreground lookup can increment hits separately; quality fresh loads also increment load. These are not live counts. |
| `evict 6` | `galleryDevModeState.evictions`; initialized at 0, sole increment at 2294 **after** the undefined helper. Inconsistent with six completed evictions under this exact unmodified source without an external helper. |
| `decoded estimate`, heap, frame | Historical source-stat dimension estimate, browser heap sample, and DEV frame sampling. None identifies the target load or validates transaction settlement. |
| `network 4g, 10 Mbps` | Browser connection information, not measured throughput of this image request. |
| `active thumb ... 1600 ...` | `shortenDevUrl(currentSource)`: shortened basename of the historical displayed source, with query omitted. It is a source label, not “1600 kbps.” |
| Bottom “Loading image...” | Written by `setLightboxNavigationLoading(true)` when no quality loader is active. The navigation timer can run after the initiating event handler has thrown. |

**CONFIRMED logical separation:** `presenting` is written before presentation clone/decode. A current transaction still at `source-selection` has not entered that clone handoff. A preview that reached presentation and then fell back to full would retain `presenting`, not reset to `source-selection`. This rules out the previous clone-decode theory as the first blocking stage of the reported transaction.

**UNKNOWN:** whether target 1793 actually generated the recent ready event. Under the proven exception sequence it can have done so: its Image exists and resolves, but no foreground continuation was attached. Alternatively all recent events can be neighbors. Neither contradicts an old preview remaining on screen.

## 4. Actual control-flow graph

All unqualified symbols are in `public/assets/gallery-modules/lightbox.js`.

1. `public/assets/public-gallery.js::bootPublicGalleryBrowserFeatures()` → deferred setup → `lightbox-deferred.js::activateFullLightbox(setupToken)` → import, page-token check, real `setupGalleryLightbox()`, click replay. Module URL uses the page's `data-gallery-asset-revision` preferentially, otherwise the deferred import revision.
2. Click/arrow → `step()` → `openAt()` → normalize index/reset zoom → `beginLightboxNavigationTransaction()`: update index; renew image/transition token; retire navigation/error/quality state; remove transition; reset queued neighbors, preserving active work; sweep cache; mark intent.
3. Missing card → mark metadata → `fetchLightboxWindowAround()` / `fetchLightboxRange()` → fetch/JSON → `mergeLightboxItems()` / `createLightboxCardFromItem()` → resume same intent. Current false/rejection now finalizes failure.
4. Present card → mark source-selection → read dataset preview/full URLs and title/controls synchronously. No asynchronous candidate selector, responsive-srcset negotiation or manifest fetch is performed here.
5. Choose `previewSrc = dataset.previewSrc || ''`, `fullSrc = dataset.fullSrc || previewSrc`, `mainSrc = fullSrc || previewSrc`. Schedule 140 ms navigation indicator for noninitial/nonprepared navigation.
6. **Critical synchronous boundary:** `preloadCardLightboxImages(card,false)` → `preloadDecodedLightboxImage()` → URL cache lookup or fresh low-priority Image → `rememberDecodedLightboxImage()` / trim. A throw here exits `openAt()` before step 7.
7. `showPreviewFirst()` → `showLightboxImageSource()` → `loadDecodedLightboxImage()` → cached promise, fresh load, or retry after cached null → onload → detached decode → current-request check → mark presenting.
8. Immediate: `commitPreparedLightboxImage()`. Otherwise same-source success or `showPreparedLightboxTransitionImage()`; slideshow delegates to that transition path. Transition clone decode → two frames → fade timer → commit display clone decode → live replacement/geometry → two frames → success.
9. Preview false → main fallback if still current; no preview → main directly. True/false/rejection → `finalizeLightboxNavigationTransaction()` → hide busy/initial; displayed or explicit failed state. Preview success independently completes navigation.
10. Success → `scheduleLightboxQualityUpgrade()` → `promoteLightboxQualityIfNeeded()` → synchronous quality candidate normalization/selection → fetch byte tracking → fresh Image/decode → `installDecodedLightboxQualityImage()` → one frame → quality-owner finalization.
11. `preloadAdjacentImages()` is called synchronously at the end of `openAt()`, not after foreground success. Queued neighbors drain via idle callback/timer. Fullscreen and resize may schedule reclamp/quality independently; there is no second fullscreen image-loading implementation.

## 5. Async boundary table

| Function | Async primitive | Owner | Can become stale? | Guaranteed settlement? | Cleanup | Risk |
|---|---|---|---|---|---|---|
| Deferred activation | import promise, idle/timer, replay | Page setup token/controller | Page replacement | No import deadline | Setup-token check/cancel listeners | Initial-only; not repeated-arrow path |
| Metadata range/window | fetch, JSON, shared promise | Metadata generation + navigation resume token | Yes | No timeout; failures become false | Identity-checked pending-map finally; navigation finalizer | Metadata stall would show metadata |
| `scheduleLightboxNavigationPending` | 140 ms timer | Index + navigation token | Yes | Timer runs when scheduled | Cancel/reassign on begin and finalization | Remains armed after synchronous setup exception |
| `preloadDecodedLightboxImage` / remember | Cached promise plus **synchronous** insertion | Exact source URL | Work survives navigation | Can throw before returning promise | Catch-null only covers load promise | Critical unhandled eviction call |
| `loadFreshDecodedLightboxImage` | Image load/error, then detached decode | Closure, optional signal; registry cancel function | Yes | No load/decode deadline | settled flag, remove handlers/signal/registry on success/error/abort | Missing event/pending decode possible; callback rejection not bridged to outer promise |
| `loadDecodedLightboxImage` | Existing promise or null retry | URL entry + caller token | Yes | Depends on cached load | Retry null; remember identity tracking | Synchronous telemetry/sweep throw can precede returned promise |
| Cache settlement observer | `preloadPromise.then(success,error)` | Entry identity | Entry replaced | Runs after original settles | Set entry.settled, then sweep | Eviction throw rejects ignored child; does not unresolve original |
| Preload drain | requestIdleCallback(timeout 350) or timer 80; per-job finally | Queue generation; shared count | Yes | Only if loader returns/settles normally | Drain flag cleared before work; count decremented in attached finally | Sync throw after increment skips finally attachment and leaks slot |
| Transition/commit | Clone decode, nested frames, fade timer, frames | Image/transition tokens and node identities | Yes | No decode/frame deadline | Stale node removal; false returned | Stage already presenting; no transitionend dependency |
| Quality | Debounce, fetch/blob or reader loop, Image/decode, install frame | Image token + quality token/controller/source | Yes | No deadline | Reader lock finally; owned progress/finalization; abort on navigation | Optional pipeline; not required for preview completion |
| Slideshow | Schedule token/timer; prepared Image promise | Slideshow token/controller | Yes | Image-dependent | Stop/navigation invalidates | Uses same display path; not necessary for incident |
| Close/hidden/teardown | Abort events; hidden cleanup timer | Viewer lifecycle | Reopen supersedes | Registered loads cancel, browser callbacks guarded | Tokens, registry, cache, queues cleared | A leaked counter is not reset by ordinary queue reset/close; new setup creates fresh state |

Image event ordering is correct in the normal fresh-load path: signal and onload/onerror handlers are installed **before** `loadedImage.src = src`. Each fresh load owns a new Image; normal navigation does not repoint that node. After success, handlers are cleared. Preview display clones cannot overwrite a pending cache node's handlers. No concrete “cached load handler installed too late” race was found.

## 6. Shared-state ownership table

| State | Identity/key and owner | Mutation/retirement | Finding |
|---|---|---|---|
| `cards`, current index | Ordered slot; card carries physical image ID | DOM/source refresh; sparse metadata merge | Index is navigation position, not cache key |
| `activeLightboxImageToken` | Monotonic intent; validity also checks index/lifecycle abort | Begin/close/teardown | Current fix handles supersession, not sync exception after begin |
| `pendingLightboxNavigationToken/Timer` | Navigation owner | Schedule/reset/finalizer | Global class is protected by caller token; timer can outlive thrown setup |
| `lightboxNavigationFailureToken` | Terminal-failure owner | Show/clear failure | Failure panel has !/failure text; not observed Loading image state |
| `activeLightboxTransitionToken`, `transitionImage`, `image` | Attempt token and DOM object identity | Clone/commit/remove; zoom/nav invalidate | Independent from detached resource readiness |
| Quality token/controller/pending source | Token + controller object + exact source | `ownsLightboxQualityRequest`, finalize/clear | New implementation guards progress and cleanup |
| `decodedLightboxImages` | **Exact raw source string** → promise,lastUsedAt,settled | LRU lookup, insert, settled-only eviction, close clear | No path-only/image-ID key; defect in eviction callback |
| `preloadedSources` | Exact URL Set | Add/clear; diagnostics | Bookkeeping; no `.has()` gate controlling foreground |
| `lightboxPreloadQueue`, `lightboxQueuedSources` | Queue records src/reason/generation; URL Set | Enqueue/dequeue/reset | Pending queue records are not promises |
| `activeLightboxPreloads` | Number of drain-owned calls | Increment before loader; decrement in attached finally | Sync exception creates real leaked slots |
| `activeDetachedLightboxImageLoads` | Set of cancel-function identities, one per fresh Image | Add before src; delete in cleanup | Different from DEV load count and queue count |
| `lightboxTelemetryCacheResults` | WeakMap keyed by Image object | Cache-hit/decoded annotation | Not a deduplication or ownership map |
| `failedLightboxQualitySources` | imageId + ':' + URL | Nonabort failures; clear on close | Quality-only inhibition, not navigation source wait |
| `lightboxPendingWindows` | offset:limit within this viewer/endpoint | Dedup; identity-checked finally; cancel lifecycle | No cross-gallery global collision |
| DEV `sourceStats` | Exact URL; source lookup selects first matching card | Last event wins; independent cache lifetime | Same physical URL may label first index; diagnostic ambiguity, not wrong resource |
| DEV current source/index/stage | Different writers/lifetimes | Index/stage on intent; source on installation | Mixes target with old visual source |
| Initial/bottom UI flags | Shared DOM classes, hidden/text/progress | Dedicated setters/failure helpers | No independent resource-ready reconciliation |

**Source/identity findings:** card preview/full are direct strings; `lightboxQualityCandidatesForCard()` parses an already-rendered JSON attribute synchronously. `lightbox-zoom-model.js::normalizeLightboxZoomQualityCandidates()` trims/deduplicates source strings; it does not fetch a manifest or resolve a responsive source asynchronously. Empty preview in lazy metadata falls back to full in `createLightboxCardFromItem()`. A failed preview load falls back to main; if preview and full are equal, retries can address the same URL.

Raw URL spelling differences can split cache entries despite browser-equivalent resources; identical URL strings intentionally share resources. Query strings remain in cache keys. No demonstrated alias causes this stall, and no evidence shows Back/Forward changing the same card's key. Do not add URL normalization as this fix.

**Normal/Smart convergence:** `app/controllers/public_gallery_lightbox.php::lightbox_image_data_attributes()` emits shared ID/gallery/source/quality fields. `app/controllers/gallery_lightbox.php::gallery_lightbox_json_item()` (~126) uses physical `image['id']`, `image_public_media_url()` and thumbnail-bundle preview 1600. `app/controllers/smart_galleries.php` calls those shared builders (~834,1238) and `render_lightbox()` (~1036). Smart metadata passes each image's physical source gallery and Smart-order index; client keys are still URL, not Smart Gallery ID. Duplicate physical representation would share a URL resource but retain separate navigation tokens/indexes. No Smart-specific cache collision is needed.

### Queue liveness proof

Without exceptions: stale-generation items are skipped **before** increment; each counted promise gets a finally decrement; aborted preloads resolve null and release slots; completion schedules drain if nonempty; reset cancels/zeros the scheduled handle; drain clears handle before executing. Queued cache hits can briefly consume a slot but normally settle/release it. Concurrency reductions can temporarily make old active count exceed the new limit; launches obey the current limit.

With the proven exception: increment → preload → synchronous trim ReferenceError → no finally. Count never decrements even after Image completion. The drain callback's handle has already been cleared, so this is **a leaked slot, not a flag stuck true**. Subsequent navigation can schedule another drain, but cannot reclaim leaked slots. After enough leaks, neighbor work stops. Foreground current-preview starts bypass this queue, so starvation alone does not explain inability to display cached/current resources.

### Bottom-panel call graph

- Navigation: `openAt` → pending timer → `setLightboxNavigationLoading(true)` → navigation class, hidden=false, indeterminate Loading image text.
- Quality: passive/explicit upgrade → `setLightboxQualityLoading(true)`; fetch progress → `setOwnedLightboxQualityProgress` → `setLightboxQualityProgress`.
- Failure: navigation finalizer → `showLightboxNavigationFailure` → error class/visible panel/! and failure text.
- Hide: begin/navigation clear, successful commit/same-source path/load catch, finalizer, quality finalizer, close/teardown. Setters preserve visibility if another loading/error class owns it.
- Initial loading: separate `showInitialLightboxLoader/hideInitialLightboxLoader` and deferred initial-loader helpers.
- `public/assets/styles/lightbox.css:545–548` displays the bottom panel for navigation, quality or error classes; hidden overrides it. No CSS completion callback clears state.

Direct-writer search found no preload callback that re-shows the bottom panel after a valid finalization. Progress callbacks change metrics, not loading classes. Current finalizer cancels the navigation timer. For the concrete defect, it never runs, so no late re-show theory is necessary.

## 7. Concrete failure sequence

**CONFIRMED reachable current-source ordering**, conditional only on ordinary cache/target state:

- **T0:** Viewer is open on A; its preview remains installed. Cache has 12 entries, at least one settled, none idle-expired at the initial sweep. New target B's preview URL is absent. Neighbor starvation from prior leaked slots can make such misses more frequent, but is not required.
- **T1:** `openAt(B)` begins token 14, records target ID 1793 and then source-selection. Begin reset leaves historical DEV current source pointing to A's preview.
- **T2:** `scheduleLightboxNavigationPending(B,14,Bpreview)` installs a 140 ms timer. `preloadCardLightboxImages(B,false)` enters the synchronous current-preview preload path.
- **T3:** Preload miss is logged; `loadFreshDecodedLightboxImage(Bpreview)` logs loading:fresh, installs handlers and assigns src. Its promise is wrapped by catch-null.
- **T4:** `rememberDecodedLightboxImage()` inserts B as entry 13 and attaches settlement observers. `trimDecodedLightboxImageCache()` selects a settled victim. `evictSettledDecodedLightboxImage()` deletes it, then throws ReferenceError at the nonexistent lookup.
- **T5:** Exception unwinds through preload/current-card/openAt. `showPreviewFirst()` and `initialMainPromise` were never evaluated. The finalizer's then/catch were never attached. Browser event-loop processing continues.
- **T6:** Pending timer sees current token/index and old image source, so shows Loading image. It has no expiry/fallback deadline.
- **T7:** B's already-started Image can load/decode normally. It logs ready:decoded and resolves its cache promise; the observer marks entry settled. Cache is now 12, so this settlement need not trigger another eviction. **No B foreground presentation consumer exists.** Stage stays source-selection, old preview stays displayed.
- **T8:** Viewer remains stuck until a new user action changes state. Other URLs may retain historical loading/preloading labels; err remains zero because ReferenceError is not an Image error.

This sequence reproduces the distinctive recent-event trio without any hung decode or network request. The unresolved item is an **abandoned navigation**, not necessarily an unresolved Image promise.

**Screenshot caveat:** it predicts no successful increment for the throwing eviction. An exact `evict 6` under a fresh instance of this exact module remains incompatible. A genuinely different loaded asset/global environment or approximation in the reported snapshot must be checked before claiming the incident conclusively solved.

## 8. Why Back -> Forward recovers

**CONFIRMED mechanism:** deletion precedes exception. B's new cache entry survives, its detached Image can settle, and the thrown navigation does not poison that resolved promise. Back begins a new token, clears the stale pending timer/class and can use cached A. Forward can use B's now-settled entry, so it needs no insertion/trim and reaches presenting/displayed normally.

This is intermittent: if a revisit needs another insertion/idle eviction, the exception can recur. Neighbor-slot leaks may persist independently, because queue reset does not zero active count. Close clears resource/cache ownership but does not repair a leaked count within the same setup closure; teardown/new setup reconstructs it.

By contrast, a **truly forever-pending cache promise** is protected from eviction and reused on Back/Forward with the same URL. Ordinary navigation does not abort it. That hypothesis predicts poor recovery unless the original operation eventually settles or another event changes cache state. This is why the synchronous-exception path ranks higher.

## 9. Root-cause ranking

1. **Synchronous eviction exception abandons navigation; related queue-slot leakage. Confidence: MEDIUM for reported incident, HIGH for current-code defect.**
   - Evidence: missing helper proven by source/import search; full-cache synchronous insertion path; pending timer before throw; finalizer after throw; ready event survives; delete-before-throw explains recovery.
   - Contradicting evidence: reported evict 6 cannot be produced by the sole increment in this exact failing implementation.
   - Distinguishes it: one reproduction reports ReferenceError at `lightbox.js:2289` with navigation token at current-preview preparation and no foreground-chain attachment. Confirm loaded asset revision.

2. **Actually pending detached Image/onload/decode or reused pending source promise. Confidence: LOW.**
   - Evidence: foreground shares URL-cache promises; no load/decode timeout; source-selection precedes detached completion.
   - Contradicting evidence: same-key revisit normally waits on the same promise; screenshot does not identify current load; repeated preload experiments failed.
   - Distinguishes it: foreground chain attached, target cache unsettled, real load phase/age identifies load-event vs decode wait, with no setup exception.

3. **Exception in detached onload/decode continuation or optional telemetry. Confidence: LOW.**
   - Evidence: nested decode then is not returned/bridged to outer rejection; settled/cleanup occurs before DEV/telemetry/resolve. Such a throw can orphan the outer promise; cache telemetry also runs synchronously during lookups.
   - Contradicting evidence: no throwing onload dependency established for the ordinary current-preview preload (which lacks foreground telemetry options). A known eviction throw in a settlement observer does not orphan the original promise. Back/Forward recovery is weaker for a truly orphaned promise.
   - Distinguishes it: ready marker/local completion without outer-promise settlement, plus matching unhandled rejection stack.

**RULED OUT as primary for this stage:** current presentation-clone decode/transition, metadata hydration still pending, optional quality completion after successful preview, simple old-owner loader cleanup omission, pure queue scheduling-flag starvation. These can have other edge cases but do not explain the current source-selection marker as well as the proven earlier exception.

## 10. Minimal safe fix

**Recommendation only; no implementation performed.**

Primary file: `public/assets/gallery-modules/lightbox.js`.

1. In `evictSettledDecodedLightboxImage()`, replace the nonexistent source lookup with existing `devFindSourceIndex(src)` (it has no DEV-enabled guard and already searches preview/full URL equality), or centralize that existing implementation under a canonical name if separately warranted. The smallest correction is reuse, not another near-duplicate helper.
2. Keep eviction telemetry optional: a telemetry exception must not abort successful cache deletion/LRU maintenance. Isolate the telemetry call and preserve normal return/counter accounting. Do not merely suppress the undefined-name error and silently discard intended telemetry.
3. Cover synchronous current-preview/cache setup in `openAt()` with the same current-token failure finalization used for asynchronous outcomes. Do not claim that a later `Promise.resolve(initialMainPromise)` catches earlier expression evaluation. Preserve synchronous ordering of source start and normal opening.
4. Make `drainLightboxPreloadQueue()` release the acquired slot if the preload function throws before returning a promise; keep the existing release on promise settlement. Invariant: each acquisition has exactly one release on every synchronous/asynchronous outcome.

The single missing-helper correction removes the demonstrated trigger. Items 2–4 prevent optional diagnostics or another synchronous cache failure from recreating an indefinite viewer stall.

**Must not change:** clone/decode presentation handoff, protected source URLs, preview-first navigation, zoom dimensions/anchoring, fullscreen viewer structure, candidate selection, cache key normalization, preload radii, navigation/quality token semantics, or no-JavaScript behavior. Do not repeat the clone-removal experiment, introduce another fullscreen viewer, or add broad preload watchdogs as the initial correction.

## 11. Implementation plan for next model

1. `lightbox.js::evictSettledDecodedLightboxImage`: use the existing source-index lookup; isolate telemetry from eviction success. Invariant: evicting a settled entry returns normally even with DEV off or telemetry unavailable/failing.
2. `lightbox.js::openAt`: include synchronous current-preview preload/cache setup in the owned failure boundary. Invariant: once the 140 ms loader is armed, every setup exception terminates that intent as failure and cancels its timer.
3. `lightbox.js::drainLightboxPreloadQueue`: balance increment on synchronous loader throw as well as promise settlement. Invariant: no leaked slot and no double decrement; remaining queue can drain.
4. Add focused regression coverage registered with the repository audit: full cache plus uncached current preview; synchronous preload throw after slot acquisition; detached completion after abandoned/superseded navigation. Assertions must cover loader/stage/display and count balance, not just helper existence.
5. Update the deployed lightbox revision through the existing `public-gallery.js` / `lightbox-deferred.js` mechanism; account for page `data-gallery-asset-revision` taking precedence. Regenerate/check managed manifest at implementation handoff and use the mandated central audit then. None was run during this static task.
6. Use one runtime reproduction to resolve the evict-counter discrepancy and confirm the expected exception is gone. If target instead remains cache-pending, use the diagnostic in section 12 to localize its actual boundary; do not reopen speculative presentation refactoring.

## 12. Suggested diagnostic enhancement

**Not implemented.** The most discriminating small addition is an owned setup-phase/error record, not more aggregate counters.

For the active token record three boundary labels: before current-preview preparation; before foreground source request; foreground continuation attached. If setup throws, preserve a bounded error name/message plus token and target ID before invoking failure finalization. Include the loaded module revision. This alone distinguishes the proven exception sequence in one reproduction.

If no exception is captured, add one active-target row: source label, target cache entry settled/pending and age, fresh-load phase (waiting-load / decoding / resolved), plus live image ID. Reuse one per-request record rather than a historical event flood. Do not infer the active record from `devStatusCounts()`.

Expected discriminator:
- preparation + ReferenceError `lightboxIndexForSource` + cache target later settled → proven abandoned setup;
- continuation attached + target unsettled waiting-load/decoding → real Image/decode wait;
- target outer promise pending after local ready + unhandled rejection → orphaned nested callback;
- source-selection with live ID from previous image → diagnostic source/index mismatch confirmed.

A full active-request dashboard, queue tracing framework or network benchmark is unnecessary for this decision.

## 13. Verification scenarios

**Not run.** After implementation:

- Populate 12 desktop cache entries (6 mobile), then navigate to an uncached preview with a settled victim. Must evict, enter presenting/displayed, hide navigation busy, and report no missing-helper error. Also exercise 60-second idle eviction.
- Rapid Next/Previous, alternating directions, A → B → C → B, cached revisits and wraparound after several evictions. Verify requested ID equals live ID and final transaction terminates.
- Force a synchronous preload/telemetry failure after loader scheduling and after queue slot acquisition. Verify failure UI, timer cancellation, balanced active count and continued navigation/drain.
- Repeat normal/fullscreen and normal/Smart Gallery navigation; cross sparse metadata windows and preview-to-full quality promotion.
- Close during detached load/decode/transition, then reopen while old callbacks settle; verify current ownership and absence of stale loader changes.
- Preserve first-click opening, centered clone handoff, zoom/fullscreen controls and no-JavaScript navigation. Do not treat passing syntax checks as proof of missing runtime-symbol coverage.

## 14. Remaining uncertainty

**UNKNOWN incident parity:** the source-selection marker proves the transaction fix was present in the reported runtime, but does not prove the eviction body matched today's file. `evict 6` is a substantive mismatch. The report neither assumes stale assets nor claims source parity was tested.

**UNKNOWN actual pending load:** `load 1` is a historical label, so it cannot establish one outstanding browser operation. The snapshot lacks target-source/real-load correlation.

**CONFIRMED scope of conclusion:** a concrete, reachable current-source exception explains an abandoned source-selection navigation and recovery without guessing a browser decode bug. Static analysis cannot prove that the supplied screenshot arose from that exact exception. If the exact runtime counter is reliable and no external helper existed, the runtime source must differ in this relevant detail; use the proposed minimal diagnostic once.

Investigation completed, not stopped by budget. Only `TEMP_LIGHTBOX_STALL_ASTRA_HIGH.md` was modified by this investigation; production files and the prior report were preserved.
