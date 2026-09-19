# Browser lifecycle ownership

## Lightbox nearby-preview queue

`public/assets/gallery-modules/lightbox-preload-lifecycle.js` owns one bounded
responsibility: scheduling and cancelling the nearby-preview queue for one
`setupGalleryLightbox()` instance. `lightbox.js` creates it through
`createLightboxPreloadLifecycle()` after validating the viewer markup. Importing
the module starts no work; construction registers one abort listener and starts
no timer or request.

The lifecycle module owns the queue, queued-source deduplication, generation,
active slot count, scheduled drain handle, and preload abort controller. The
viewer supplies authorized sources, decoding, connection policy, and optional
diagnostics. There is no second foreground navigation generation or event bus.
The decoded cache, metadata requests, slideshow-original preparation, quality
promotion, DOM listeners, hidden-cache timer, and viewer presentation remain in
`lightbox.js`.

### API and integration contract

The factory takes the setup owner's `signal`, a `preload(src, {signal}, reason)`
callback, a `concurrency()` callback, optional `onError(error, src)` diagnostics,
and a scheduler (the viewer passes `window`). The preload callback must return
its completion promise and honor cancellation. The existing detached loader
removes its source and settles on abort; the queue does not claim to cancel
arbitrary promises itself. Concurrency policy returns one or two slots, as
before: one for mobile/touch, 3G, Save-Data, or slower connections; two otherwise.

| API | Ownership and effect |
| --- | --- |
| `enqueue(src, reason, generation)` | Deduplicates queued URLs and schedules FIFO work. Empty sources, obsolete generations, and disposed instances are ignored. Omitted generation means the current one. |
| `reset({abortActive: false})` | Advances the generation, clears queued work and its scheduled callback, and preserves already running requests and their signal. |
| `reset()` | Also aborts active nearby/immediate preview work and creates a fresh signal for reopening. It does not reset the active count early: old promises release their own slots when they settle. |
| `dispose()` | Terminal, idempotent cancellation. Removes the setup abort listener, clears queued work/timers, aborts the signal, and refuses later work. Setup owner abort calls it automatically. |
| `generation`, `signal` | Read-only access to the single queue generation and current cancellation signal. Immediate current-preview warming shares this signal without occupying a queued slot. |
| `snapshot()` | Returns copied scalar diagnostics; queue containers and counters cannot be mutated by the viewer. |

Idle scheduling retains a 350 ms timeout and uses an 80 ms `setTimeout` fallback.
At most one drain is scheduled at a time. The callback checks its captured queue
generation, so a callback dispatched before cancellation cannot drain a reopened
queue or clear that queue's handle. Successful or rejected preload promises each
release one slot. Synchronous decoder or diagnostic errors cannot strand the
remaining queue. Rejected background promises are consumed after releasing their
slot; foreground presentation keeps its existing error path.

| Viewer event | Queue action |
| --- | --- |
| Ordinary next/previous navigation | `reset({abortActive: false})`; active previews stay reusable. |
| Close or backgrounding an open viewer | `reset()`; obsolete neighbors and active preview work are cancelled. Reopen uses the same owner with a fresh signal. |
| Public fragment replacement / setup teardown | The setup controller aborts, permanently disposing the old queue. The replacement setup creates its own owner. |

`resetLightboxPreloadQueue()` remains the viewer adapter that clears
`preloadedSources` diagnostic bookkeeping and delegates reset options.
`queueDecodedLightboxPreload()` retains immediate decoded-cache reuse and
delegates uncached sources. Close and teardown still cancel other detached
loads, clear the decoded cache, and release viewer resources through their
existing owners. Settling promises may briefly retain a retired instance until
their completion microtasks run; they cannot schedule more work after disposal.

### Public behavior boundaries

Both permanent thumbnail renderers (`progressive` and `responsive`) feed the
same card-source adapter. Nearby warming still selects only a provided preview;
metadata-only cards cannot substitute an original for a missing preview.
Neighbor full-source radius remains zero. Existing authorized card URLs are
passed through unchanged; this module introduces no media endpoint or fetch.

The single viewer, centered 100% geometry, 100-400% zoom range, pan/fullscreen
controls, and synchronous assignment of the active original on deliberate zoom
stay in the existing viewer. Slideshow-only full-image preparation and the
no-JavaScript navigation fallback also retain their existing paths.

### Verification and central integration

`tests/lightbox_preload_lifecycle_test.mjs` executes the actual lifecycle module
with controlled scheduling and promises. It covers FIFO/deduplication, both timer
APIs (including handle zero), dynamic concurrency, soft/hard reset, stale callback
suppression, synchronous throws, rejected promises, diagnostic failure, terminal
disposal, pre-aborted setup, and 30 repeated close/reopen/fragment-owner replacement
cycles. It also executes the production viewer factory and source-selection/reset
adapters with both renderer labels to check preview-only warming, immediate cache
reuse, shared cancellation, and mobile/connection policy. These are runtime seam
tests, not a full DOM/browser rendering simulation or server authorization test.

The focused fixture passed 12/12 cases on 2026-09-20 using Node v24.18.1.
A central PASS is not inferred from these focused results.

`lightbox_preload_lifecycle_test.mjs` is registered in the `node_tests` map in
`scripts/audit_registry.php`. No browser fixture or special arguments are needed.
The three existing PHP contracts below now read the moved queue code from its
owner while continuing to check the viewer adapters; their unrelated assertions
remain in place:

- `tests/lightbox_resource_lifecycle_test.php`
- `tests/lightbox_navigation_loading_regression_test.php`
- `tests/lightbox_cache_eviction_liveness_test.php`

The central audit remains responsible for these contracts, the zoom and slideshow
contracts, JavaScript syntax, and other registered suites. The measurement helper
below is an explicit reporting command, not another runtime test registration.

### Asset revisions and deployment integration

The viewer imports the new module with
`?v=20260920-lightbox-preload-lifecycle-v1`. Both public entrypoints and the
Admin side panel import the deferred loader at that revision. The side-panel
change propagates through the Admin operations re-export and entrypoint.
The new lifecycle asset participates in both `$scriptVersionPaths` arrays in
`app/views/layout.php` beside `lightbox.js`.
Future lifecycle-module edits must also bump the versioned import in `lightbox.js`.

`lightbox-deferred.js` already propagates `data-gallery-asset-revision`, falling
back to its own import revision, into the dynamic `lightbox.js` URL. Its loader
implementation needs no change. Regenerate and check `app/core-manifest.json` after final
source edits and before deployment/handoff packaging.

## Source and compression measurements

Baseline: Git commit `1b823be89095415b3e0991acb9f0a5b3002df024`. The initial viewer
was unmodified relative to this baseline. Measurement uses UTF-8 source with LF
line endings on both sides, gzip level 9 and Brotli quality 11, compressing each
asset independently before summing. This removes checkout CRLF differences and
models separate compressed bodies without claiming actual HTTP transfer sizes.
Runtime: Node v24.18.1, zlib 1.3.1-e00f703, Brotli 1.2.0. Measured 2026-09-20.

| Asset / revision | Lines | Source bytes | gzip bytes | Brotli bytes |
| --- | ---: | ---: | ---: | ---: |
| Baseline `lightbox.js` | 7,792 | 352,983 | 67,564 | 53,837 |
| After `lightbox.js` | 7,709 | 349,249 | 66,919 | 53,370 |
| New lifecycle module | 174 | 7,313 | 2,108 | 1,751 |
| After, combined | 7,883 | 356,562 | 69,027 | 55,121 |
| Combined change | +91 | +3,579 | +1,463 | +1,284 |

Reproduce from the repository root:

```text
node tests/support/lightbox_lifecycle_size.mjs 1b823be89095415b3e0991acb9f0a5b3002df024
```

The helper prints per-asset SHA-256 fingerprints as well as totals, compression
versions, and deltas. It reads the baseline directly from Git and the after files
from the working tree. Other imports and assets are excluded from this slice's
totals; the new module adds one static dependency to the deferred viewer graph.
The parent file shrank by 83 lines and 3,734 bytes, but the combined compressed
payload grew. The measured benefit is a separately testable lifecycle owner,
not a demonstrated startup or navigation speed improvement.

## Operational measurement gap

Real-phone browser benchmarking is unavailable for this slice. Representative
desktop/phone compressed HTTP transfer, parse/evaluation, initialization timing,
rendering, decoded-memory behavior, and long-session interaction measurements
have not been collected. Node fixture durations are not browser speed evidence.
No additional lazy-loading change is justified by these size results alone.

Before claiming a loading improvement, compare the same gallery and device under
anonymous, logged-in, and Admin sessions, with cold and warm caches and both
thumbnail renderers. Record server compression and Resource Timing transfer
sizes separately from source compression, and browser parse/evaluation/setup
timing separately from image decoding and network latency. Repeat open/close,
rapid navigation, background/foreground, and public fragment replacement while
checking retained listeners and requests. Complete the existing zoom/fullscreen,
mobile swipe, maps, voting, strip/carousel, slideshow, protected-media, and
no-JavaScript browser matrix in `TESTING.md`. These operational checks remain
open even when central deterministic contracts pass.
