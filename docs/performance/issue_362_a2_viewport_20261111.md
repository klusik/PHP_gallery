# Issue #362: A2.6 settled visible viewport and local queue diagnostics

**Status:** Small A2.6 implementation on the existing #362 feature branch. Not overall A2 acceptance, WEDOS staging qualification, thumbnail HTTP batching, PHP worker measurement or a release.

## Actual visible viewport contract

The real native Tk viewer retains its pre-existing visible-index calculation, one-row overscan, disk lane, session gate, worker priorities and 24-ms coalescing. A2.6 adds a diagnostic-only ViewportPerformanceWindow. When the Tk owner accepts a changed **actual visible** set, it starts one local monotonic epoch. Overscan tiles never enlarge its target. Only after the next accepted coalesced Tk viewport pass may it settle that epoch. Every visible tile must already belong to the current admitted band and be present in the existing terminal `_thumbnail_loaded` set. The sampler separately counts successfully configured non-null `ImageTk.PhotoImage` objects and completed unavailable placeholders; it does not label failures as images.

A scroll that changes the visible tile set supersedes any unsettled previous epoch. Old tokens, incomplete bands, empty and hidden views, refresh-pending bands and duplicate completion attempts do not produce settled duration samples. Rebuilt photo descriptors invalidate the previous visible-band baseline. The existing cancellation, generation, active gallery, session and page-result validation remain the authority for all thumbnail results. The profiler does not initiate or delay any network request, image decoding, Tk callback or a new thread.

Each viewer retains at most **256 settled numeric samples within 300 monotonic seconds**. Its current-band status is explicitly `empty`, `pending` or `settled`; p50/p95/p99 only describe genuine settled durations. A first settled result may contain terminal unavailable tiles and must be read alongside the successful/unavailable counts. The cumulative number of interrupted visible-band epochs is separately labelled `superseded_lifetime`, not presented as a five-minute statistic. The sample retains counts and times, never image IDs, photo indices, names, URL values or credentials.

## Startup and queue diagnostic output

`PhotosWorkspace` accepts `first_viewport_settled` only from its mounted viewer with the captured session, gallery and catalogue generation and an uncancelled current pass. The existing `CataloguePerformanceWindow` stores it once with the other first-use Tk milestones and exposes a `fresh_to_first_viewport_settled_ms` delta only if fresh authorized rows preceded it. A resolved band is **not a guarantee of successful images**; the separate viewport line reports successful images versus terminal placeholders.

The existing copyable Photos diagnostic line adds instantaneous, local counters: `visible_unsettled`, `pending_thumbnail`, `pending_local`, `queued_gui_results` and `worker_active`. These are a snapshot of client-side admission and delivery, not measured WEDOS/PHP worker occupancy, network concurrency or total host queue depth. Tk state records widget binding and mapped geometry, **not a verified Windows compositor scan-out**.

## Hosted tests and limitations

The existing issue-scoped A2 workflow runs portable deterministic viewport tests on both GitHub Ubuntu and Windows with Python 3.14, plus existing real Windows Tk Photos tests. The first-gallery fixture verifies the genuine displayed-thumbnail and first fully resolved visible-band milestones, safe local queue counters, full successful visible band, and a fresh-to-viewport delta. The portable tests cover incomplete/empty bands, terminal unavailable outcomes, stale epochs, double settlement, superseded scrolling, nearest-rank percentiles, retention caps, expiry, non-finite/negative/bool and reversed monotonic timestamps. The same workflow retains its independent PHP 8.1/8.5 + MariaDB 10.11 authorization and Server-Timing fixtures.

The initial first-viewport milestone is emitted only once for a viewer; subsequent changes still enter the viewer's bounded viewport sample window. A manual catalogue refresh may reset the workspace startup pass while retaining an already-mounted viewer, so a *new* first viewport milestone is not asserted without a fresh viewer callback. This is a documented scope boundary, not an invented measurement. Final A2 acceptance must consider refresh/mixed-version behavior and complete FULL CI separately.

No user PC, WEDOS production host, new endpoint, background daemon, Composer/npm, Redis, SQL schema, cache policy or release is involved. The parent A2 checkbox stays unchecked until a separate final acceptance audit. No public legacy API-key documentation or localized TeX manual change is appropriate for these internal diagnostics.
