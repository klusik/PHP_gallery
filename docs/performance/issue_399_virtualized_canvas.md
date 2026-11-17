# Issue 399 virtualized Canvas scrolling experiment

The active primary matrix, narrowed by the owner on 2026-11-17, contains
100, 500 and 2000 photos: 12 pilot processes, 60 repeated baseline processes
and 120 paired-comparison processes. The 5000-photo/79-page case is a separate
stress test; a reported timeout does not block P1. A valid repeated primary
baseline is sufficient to start the actual Canvas prototype. Immediately after
the first functional prototype, run targeted hosted checks and an orientational
A/B observation, then optimize and complete statistical qualification. Earlier
16/80/160 counts below belong to the superseded four-extent calibration, not
the active blocking matrix. No invalid result is reclassified as PASS.

This opt-in experiment measures Photos rendering at fixed loaded catalogue extents and records metadata admission separately. The existing issue 361 benchmark driver remains the execution and evidence owner. Select it with PHP_GALLERY_TK_PERF_PROTOCOL=issue_399_tk_protocol.json; the protocol workload_script selects the issue 399 harness. The central audit remains the only test orchestrator. The primary pilot has two complete independent PASS executions. This repeated descriptive baseline admits the opt-in prototype under the owner's revised priority; inferential statistical qualification remains pending.

## Source and workload identity

The frozen comparison baseline is `ab245719ddf65ccba51a83ac10564a795a03dee9` (WinApp 1.5.0), integrated by [PR #398](https://github.com/klusik/PHP_gallery/pull/398) after [27-owner FULL qualification](https://github.com/klusik/PHP_gallery/actions/runs/44006942461). The source archive is extracted from that exact commit; the candidate is bound to its exact commit SHA. Both sides use the same checked-out harness, protocol, Python dependencies, synthetic catalogue seed, VM, and ordered actions. Each workload is an independent process. Reports retain source SHA, scenario, cache state, trial, instrumentation, raw GUI observations, and metadata. Production accounts, network requests, and real galleries are excluded.

The harness prepares deterministic metadata through the production 64-photo page reader before the timed renderer interaction phase. The catalogues contain 100, 500, 2000, and 5000 photos, spanning 2, 8, 32, and 79 actual media pages. This isolates renderer performance at each loaded extent from network latency; page admission/read counts and preparation duration are reported separately from timed scrolling. The harness also reports scroll response and settled interaction timing, event-loop drift, callbacks, image publication, and memory/resource observations. It must fail qualification when page reads or required timing observations are missing. This P0 experiment does not claim to measure HTTP latency or validate progressive page admission caused by scrolling; a separate functional paging check owns that behavior.

## Protocol and numeric policies

The protocol JSON is the machine-readable owner for operational values. These values are repeated here with their types, units, scopes, consumers, and reasons so the experiment remains reviewable without interpreting raw JSON.

| Policy | Type and units | Scope and consumer | Value and rationale |
| --- | --- | --- | --- |
| Protocol schema | integer version | Protocol/report readers | 1; identifies this report layout, not a performance threshold. |
| Catalogue extents | integer photos | One scroll scenario and its synthetic catalogue | 100, 500, 2000, 5000; span small through large loaded extents and expose paging growth. |
| Page capacity | integer photos/page | Synthetic media descriptor paging | 64; matches the production page admission contract. |
| Pilot | integer processes/cell | Each scenario/cache/instrumentation combination | 1; confirms all 16 cells and reports descriptive instrumentation overhead only. |
| Baseline | integer independent processes/cell | Each scenario/cache combination | 10; supplies repeated process-level measurements. |
| Comparison | integer paired process runs/cell | Each scenario/cache combination | 10; keeps baseline and candidate paired by trial identity. |
| Bootstrap | integer resamples | Each paired metric and scenario/cache cell | 2000; deterministic paired percentile interval estimation. |
| Bootstrap interval | tuple of numeric percentile fractions | One paired metric interval | 0.025, 0.975; central 95 percent percentile bounds. |
| Child timeout | integer seconds | One independent workload process | 90; terminates hung GUI scenarios. |
| Driver timeout | integer seconds | One full benchmark stage | 2400; bounds hosted runner occupancy. |
| Workload seed | integer seed | Every scenario process | 3612026; reproduces synthetic data and operation ordering across sources. |
| Viewport positions | tuple of numeric fractions | Scroll action sequence | 0.0 through 1.0; exercise top, middle, and bottom admission while preserving a bounded repeatable action set. |
| Window geometry | integer logical pixels | Initial client and resize actions | Initial 900×680 at origin; resize to 640 or 960 pixels at origin after screen/maxsize qualification. |

With four extents, two cache states, and two instrumentation modes, the
complete pilot requires 16 processes.

The initial full pilot [44011416072](https://github.com/klusik/PHP_gallery/actions/runs/44011416072), at workflow/harness SHA
`01379b6b78ca178d1b87d892c2a3ef27fb830d3f`, and targeted diagnostic
[44012529759](https://github.com/klusik/PHP_gallery/actions/runs/44012529759), at SHA
`9d4ea91b0766032aef3153939da06892cce8d23b`, are invalid attempts. The
targeted run requested a 1100-pixel client width, but Windows constrained the
window to 1028×680; strict root-width waiting hung although all 24 visible
items were loaded, the event queue was empty, and no worker remained. The
harness contract uses an initial 900×680 window and fixed 640- and 960-pixel
client widths at origin, qualifying screen/maxsize geometry before the
unchanged strict-width check.

A subsequent full pilot,
[44014467364](https://github.com/klusik/PHP_gallery/actions/runs/44014467364),
at workflow/harness SHA `5d2bfd26139ded40488fb437bcfc8d6192e5392e`,
completed the 100- and 500-photo extents (8/16 cells). At the 2,000-photo
cold/instrumentation-on cell, all 50 input actions and three lifecycle transitions
passed, but catalogue-preparation callbacks filled the 20,000 raw-event cap
(20,000 retained rows and 20,265 additional observations truncated) before
required interaction-feedback raw evidence existed.
The full pilot and its partial records are invalid and not accepted.
These workflow/harness SHAs are distinct from application baseline A
(`ab245719ddf65ccba51a83ac10564a795a03dee9`). These historical failed attempts do not qualify a baseline; the current pragmatic P0 admission is recorded below.

## Experimental sequence

1. Run the unfiltered 12-process primary pilot. It is descriptive and cannot qualify baseline or comparison evidence.
2. Under the owner's revised priority, two complete independent primary pilots admit P1 as a repeated descriptive baseline. Preserve hardware cohorts separately. The 60-process baseline and ten-pair comparison remain statistical qualification; neither a partial failed matrix nor an n=1 pilot qualifies that statistical gate.
3. After the first functional prototype and orientational A/B check, run 120 primary source-paired comparison processes. Alternate baseline/candidate order by pair (AB, then BA) to reduce order bias.
4. Accept only complete exact-SHA reports with ten matched trial identities per scenario/cache cell, valid required metrics, and zero truncated raw events.

The recorder separates setup from timed interactions. It counts actual Tk
operations in each phase and aggregates setup callback timings as fixed count,
total, and maximum values instead of emitting one raw event per setup callback.
Initial mount, first-page admission, first viewport, and full-catalogue
preparation remain separate scalar phase timings. Cold and warm measured raw
scroll events begin only after catalogue admission; warm-trace priming is
excluded. The 20,000-event cap and 90-second child / 2,400-second driver
deadlines remain unchanged; measured-event truncation still fails qualification.

Cold means a fresh process and empty application/image cache; it does not imply an empty operating-system file cache. Warm means replaying the same fixed interaction trace once before its measured replay in the same process, retaining the real bounded thumbnail cache. Initial controls and catalogue admission are fresh-process setup in both regimes; the warm label applies to the interaction trace, not a preloaded full catalogue or OS cache. Event rows are observations within a process and never count as independent trial units.

## Required evidence and limits

Instrumentation-on reports require event_loop_drift_p95_ms, callback_p95_ms, interaction_feedback_p95_ms, and image_publication_p95_ms, each backed by measured raw events. Scroll runs additionally require viewport_settle_p95_ms, wheel_slow_p95_ms, wheel_fast_p95_ms, drag_p95_ms and resize_p95_ms backed by their own measured raw events. Instrumentation off reports require scenario_elapsed_ms. Physical monitor presentation latency remains unavailable unless directly measured; it must not be inferred from callback or image-publication timing.

This protocol defines an experiment, not a result or a claim of improvement. The benchmark driver publishes the complete generated `summary.md` and concise JSON to the GitHub Actions job summary; the issue comment remains compact and links the artifact. The eventual report must include exact baseline/candidate and harness digests, runner/runtime metadata, all child records, paired deltas, ratios, and bootstrap intervals. A completed pilot alone is not benchmark acceptance.
## Input, lifecycle and renderer admission

Slow wheel input uses four downward and four upward events at a 100 ms cadence.
Fast wheel uses twelve downward and four upward events at 10 ms; scrollbar
commands stream sixteen positions at 16 ms. Fast input does not wait for images
after every event. Idle feedback, last-input settling and whole-burst completion
are separate observations; burst completion includes the intended input period.
Jumps cover 25%, 50%, 90% and the top; resize uses fixed 640- and 960-pixel client widths at origin. The initial window is 900×680 at origin. Screen/maxsize constraints are checked before the unchanged strict-width wait.
Tk handlers and idle callbacks are software proxies, not monitor presentation.

After primary timings are captured, a separate 100-photo fixture performs one
gallery switch and two reopens. It verifies real page cursors, exact IDs/order,
closed frames, worker retirement and cancellation of viewer-owned timers. Its
three transition timings are reported separately; it does not claim a 5000-photo
switch result. Optional screenshots are limited to trial zero with instrumentation
on, use complete source/scenario/cache/trial file identities, and remain advisory.

Both sources receive `PHP_GALLERY_CANVAS_GRID=1`. The optimized control ignores
that future opt-in; a candidate can implement it without changing frozen harness
bytes. The report records the actual renderer. Keyboard focus requires either the
exact native tile or a focused Canvas with the exact logical photo index. This
is functional focus validation, not a screen-reader accessibility certification.
The production renderer was unchanged during the accepted P0 descriptive baseline. The subsequent prototype is an isolated opt-in; the native renderer remains the default accessible fallback.

## Pilot harness repair

The geometry-corrected full pilot at workflow/harness SHA
`9b92f9b962db4e22186383ecd4d88f00529b7fa3` failed in
[run 44014046202](https://github.com/klusik/PHP_gallery/actions/runs/44014046202)
after all 50 input actions, with exact 640/960 resize observations. The lifecycle
stage referenced an uninitialized before-close counter; review also found an
uninitialized completed-transition counter. Both are harness state defects.
The repair initializes them before their first read while retaining owned timer,
worker, exact ID/page and transition checks. This failed run contributes no
qualifying pilot or baseline records. Application control A is unchanged.

No TeX manual impact: this repairs internal evidence state for the already
documented lifecycle sequence; geometry, operations, limits and product behavior
are unchanged.

## Large-catalogue pilot timeout

[Run 44016215794](https://github.com/klusik/PHP_gallery/actions/runs/44016215794)
at workflow/harness SHA `612a38df0e7601874a1cc1da4db97459432e705b` completed
12/16 pilot processes, covering every 100-, 500- and 2000-photo cell. The first
5000-photo cold/instrumentation-on child exceeded the unchanged outer
90-second limit before its final JSON was available. This full pilot and its
partial records do not qualify baseline.

The existing authenticated artifact inspector is selected for read-only diagnosis
of saved process intervals. Repeated deterministic fixture generation is a
source-level candidate for harness overhead, not a measured root cause yet.
Application control A, raw limits and deadlines were unchanged in this historical attempt. The later owner-approved pragmatic admission is recorded below.

No TeX manual impact: this selection only inspects a failed run through the
existing evidence reader and does not change the documented measurement method.

## Deterministic fixture bundle and latest timeout

The harness is changing its fixture transport to reduce repeated synthetic image generation. The existing PNG/JPEG generators run once per driver to produce a deterministic `ZIP_STORED` archive with a fixed timestamp. Its manifest records the workload seed, the fixture generator's common-module SHA, Pillow version, and each body length and SHA-256. Every independent child receives the expected archive SHA, verifies the manifest and body hashes, loads only its requested prefix, recomputes its dataset digest and checks that digest against the mounted gallery. Child application profiles, sessions, and application caches remain isolated. Fixture preparation time is reported as a separate stage; each child's `fixture_load_ms` reports archive verification/loading, while `scenario_elapsed_ms` starts afterward and ends before lifecycle, while existing GUI startup scalars and the primary scroll CPU interval keep their prior definitions. This changes harness setup accounting only. It is not evidence of lower application cost or improved rendering. The driver child `elapsed_seconds` includes archive loading, lifecycle and teardown; application profiles and cache isolation remain unchanged.

The preceding full pilot [44016215794](https://github.com/klusik/PHP_gallery/actions/runs/44016215794), at workflow/source SHA `612a38df0e7601874a1cc1da4db97459432e705b`, saved 12 of 16 process rows and timed out at the unchanged outer 90-second limit before the first 5,000-photo cold/instrumentation-on child produced its final report. A read-only diagnostic [issue comment 6938434856](https://github.com/klusik/PHP_gallery/issues/399#issuecomment-6938434856) found 12 saved child intervals totaling 398.6 seconds; the slowest saved 2,000-photo cells took 62.5–78.6 seconds. It did not establish the timeout's root cause. The timed-out full pilot and partial rows remain invalid and do not qualify a baseline. The deterministic bundle is a setup-overhead investigation, not a performance result; P0 acceptance and repeated baselines remain outstanding.

The extended read-only [phase diagnostic](https://github.com/klusik/PHP_gallery/issues/399#issuecomment-6938664490)
reported 34.6–34.8 seconds for catalogue admission and 14.0–14.4 seconds of
measured interaction CPU in the saved 2000-photo instrumentation-on processes.
These earlier diagnostic observations locate real GUI cost; they do not prove
the bundle fixes the 5000-photo timeout or qualify baseline.

| Bundle/progress policy | Type and units | Scope and consumer | Value and reason |
| --- | --- | --- | --- |
| Archive bound | integer bytes | Shared writer/reader, one stage-owned ZIP | 134217728 (128 MiB); bounds temporary input storage. |
| Manifest bound | integer bytes | Shared writer/reader, one JSON member | 1048576 (1 MiB); contains the fixed catalogue hashes. |
| Member bound | integer bytes | Shared writer/reader, one image body | 33554432 (32 MiB); refuses oversized PNG/JPEG input. |
| Catalogue bound | integer photos | Shared fixture bundle | 5000; matches the largest fixed extent. |
| Progress bound | integer bytes | Driver diagnostic reader, one sidecar | 16384 (16 KiB); phase counters cannot become bulk evidence. |
| Checkpoint interval | integer page reads/input actions | Child diagnostic writer | 8; bounds diagnostic I/O while retaining phase progress. |

The formerly selected scroll5000-only diagnosis did not qualify baseline.
The active primary pilot and repeated baseline now cover 100/500/2000 only;
the separate 5000-photo stress outcome cannot block P1.

## Checkpoint constant repair

The targeted [run 44021541297](https://github.com/klusik/PHP_gallery/actions/runs/44021541297)
failed before catalogue admission because `PROGRESS_CHECKPOINT_INTERVAL` was
referenced without a definition. The repair defines the documented eight-unit
interval and adds a focused stdlib-symbol-table regression for unresolved global
reads in the existing harness, including nested callbacks. This failure provides
no new 5000-photo timing observation and is not qualifying evidence.

No TeX manual impact for this repair: it restores the existing internal progress
policy and checks name binding; the owner-approved primary/stress scope update
is documented separately with its protocol change.

## P0 baseline accepted and first Canvas prototype

On 2026-11-17, the owner prioritized a functional Canvas prototype after a valid
repeated primary baseline, with complete statistical qualification afterwards.
The same frozen [pilot run](https://github.com/klusik/PHP_gallery/actions/runs/44024594972)
passed **12/12** in each of two independent executions:
[attempt 1](https://github.com/klusik/PHP_gallery/actions/runs/44024594972/attempts/1)
on an EPYC 9V74 and
[attempt 2](https://github.com/klusik/PHP_gallery/actions/runs/44024594972/attempts/2)
on an EPYC 9V45. Both cover 100/500/2000 photos, cold/warm and instrumentation
on/off, including strict interaction, identity, raw completeness and lifecycle
checks. The application is control A above; workflow/harness source is
`ef28be7741c4ffb0de5e72d595578efff2e42adf` in both executions.

| Frozen input | SHA-256 |
| --- | --- |
| Harness | `3d1b0042f59a00246ce10488644046690ee915cc672b2e0ba1b3748ad4f31d3c` |
| Common workload | `f084c93cd05b4c1367abb092d0c6cbb9c3dbf741457bb4784ab1dd5b80d22729` |
| Driver | `59e00893cefa8c162019a006434ce2804d8d204bc26c1a4ae7343b419f4c85b5` |
| Protocol semantics | `67212362c1e8fa1f0569a6a245dc5a4621629a376cb4e31e047145d99dc83a7f` |
| Fixture archive | `445547351a006fb7e19e16610011e1179cbc833dbb4b8a60f7a7078750bf4be3` |

This is **two observations per instrumentation cell, descriptive P0 admission**,
not an inferential baseline or paired speedup. Hardware cohorts are not pooled.
The [60-child baseline](https://github.com/klusik/PHP_gallery/actions/runs/44025674818)
first failed after only 5/60 valid children on an EPYC 7763: the 2000-photo warm
child completed all 50 primary interactions but reached the existing overall
80-second GUI deadline at the lifecycle boundary. Zero raw events were truncated.
Read-only review found no confirmed teardown bug. That attempt remains FAIL;
its unchanged retry completed **PASS 60/60** on an EPYC 9V74 in
[attempt 2](https://github.com/klusik/PHP_gallery/actions/runs/44025674818/attempts/2),
with [raw artifact 14305630104](https://github.com/klusik/PHP_gallery/actions/runs/44025674818/artifacts/14305630104).
All ten repetitions in each primary cold/warm cell passed. It completed while
P1 was implemented; no limit was raised and the failed hardware cohort is not
pooled into the successful cohort.

The control creates 114/514/2014 native widgets at the three extents, while live
PhotoImages are already admission-bounded. P1 targets this catalogue-sized
widget/layout work with retained Canvas primitives bounded by the existing
admitted viewport. The viewer continues to own authorization, media/cache,
workers, cancellation, generations and UI-thread publication. The native path
remains the default accessible fallback while the Canvas path is qualified.

Before initial A/B, correct the anticipatory Canvas harness key precondition:
focus the target logical tile as the native path already does. Do not change
application focus semantics to match the faulty precondition. Both A and B must
use the corrected identical harness and record its new digest.

Prospective adoption gates were declared in
[the P0/P1 checkpoint](https://github.com/klusik/PHP_gallery/issues/399#issuecomment-6940125047)
before candidate timings: correctness/security and current-viewport resource
bounds are mandatory; the primary large-catalogue latency hypothesis requires
paired improvement and uncertainty assessment. A pilot is descriptive and
cannot decide adoption. Physical display latency and screen-reader parity
remain unverified; the default native fallback is retained.

No TeX manual impact for this checkpoint: it records experiment admission and
architecture, without changing the default product flow or maintainer command.

## Opt-in retained renderer prototype

`winapp/uploader/canvas_gallery_grid.py` owns only Canvas presentation:
three retained image/placeholder/selection primitives per current admitted
index, plus one unpainted full-content extent item. Plain Python catalogue
records/proxies scale with loaded metadata; live Tk widgets, drawing primitives
and PhotoImage owners remain bounded by the current viewport and its existing
one-row-per-side overscan. Reconcile preserves slots for surviving identities
and recycles outgoing slots; resize trims the current pool. Native grid buttons
and per-photo Tcl variables are not constructed in this backend.

The native viewer reads `PHP_GALLERY_CANVAS_GRID=1` once per instance.
It retains wheel/touchpad/resize coalescing, metadata paging, cache/proof/session
checks, generations, cancellation, selection, detail/lightbox and thumbnail
publication. Canvas proxies translate tile-local events into real Canvas
coordinates and retain logical keyboard identity. Home/End/PageUp/PageDown
use loaded logical geometry. Actual image/placeholder plus rim bounds determine
hit testing and exclude unused cell space. Theme palette, fonts and rounded
geometry remain in `ui_theme.py`.

The first checkpoint adds seven cross-platform real-Tk helper regressions and
an opt-in viewer regression for keyboard selection, reorder image retention
and stale proxy rejection. They run through existing hosted audit discovery;
no local execution or new test registry. Source review is not test evidence.
The first checkpoint omitted Canvas hover tooltips; the next focused repair
restores pointer-only help through the existing shared toplevel owner, with one
active Tcl variable and exact current image/rim anchoring. Native default
behavior is retained.

The first orientational measurement uses optional `orientation_ab=true` only
with the existing `pilot` stage. The workflow runs the same registered audit
on A, then on exact candidate B on the same VM after A passes, retaining both
normal audit directories and existing job summaries. B's measured source is
validated against the exact event SHA. This is an ordered descriptive A→B
pilot, not the balanced ten-pair comparison; no confidence interval or speedup
claim follows from it. Delete the optional pilot-only property for the formal
comparison. Driver/common logic, ten-pair statistics and all deadlines remain
unchanged. The narrow logical-focus precondition correction applies identical
harness bytes to both sources.

No TeX manual impact for the opt-in prototype: the default user workflow,
controls, installer and documented account permissions remain unchanged.
The internal qualification switch and known gaps are recorded here and in the
WinApp developer README. User-facing adoption will require manual updates.

## First remote prototype findings and repair

[Source 02f51a08](https://github.com/klusik/PHP_gallery/commit/02f51a08af5bbabbe4778a822038310194e6afe3)
passed BASIC and completed the same-VM
[descriptive A→B pilot](https://github.com/klusik/PHP_gallery/actions/runs/44031985098)
with 12 children for each source; B's report confirms actual renderer `canvas`
and 13 native widgets at every primary extent. Its one-process-per-mode
feedback observations are descriptive; no statistical speedup is claimed.
The [native suite](https://github.com/klusik/PHP_gallery/actions/runs/44031985038)
failed on both required platforms because a new resource test omitted its
widget-count helper and repeated modifier clicks did not reach range selection.
The opt-in viewer keyboard/reorder/image identity regression passed. The source
is not functionally qualified; frozen validation was correctly skipped.

The repair supplies the missing test helper without weakening assertions and
routes modified double-click events through range/toggle/context owners.
Unmodified double-click continues through the existing photo-open owner.
Canvas hover help now shares the toplevel tooltip owner, preserves covered-widget
checks, captures catalogue epoch at append, and cancels on scroll/recycle,
reset, resize and close. Trigger-filtered dismissal cannot hide another control's
help. Existing tooltip timing policies and default callers are preserved.

No TeX manual impact: internal opt-in backend correctness and existing tooltip
parity, with unchanged default controls and permissions. Hosted outcomes for the
repair must be checked separately; earlier-SHA passes do not qualify it.

## Dirty rendering and Canvas interaction qualification

The next source checkpoint caches only per-slot coordinate tuples, Tcl image
names and paint flags. Unchanged geometry/image/text/rim state issues no Canvas
writes; changed parts still update their real primitives. These caches are
cleared before every identity rebind and on close, and introduce no additional
PhotoImage/cache/worker owner. This targets the redundant calls observed in
the first descriptive pilot rather than increasing benchmark instrumentation.

Canvas refresh now captures keyboard identity by photo ID only when the Canvas
owns actual Tk focus. Reordering/insertion restores the surviving ID without
scrolling or stealing toolbar/lightbox focus; deletion chooses the nearest valid
loaded index. The native path and its original cancellation regression remain
unchanged, with separate opt-in coverage added.

Hosted regressions additionally exercise live hover/status text, gutters,
covered triggers, scroll/reset/close dismissal, unrelated toolbar tooltip
ownership, zero-write repeated admission and real selection repaint. Windows
render evidence captures a known PhotoImage and selected rim at actual
640/960-pixel Canvas widths under `cache/test-audit/canvas-399-visuals`.
It checks capture dimensions and the rendered image-center RGB value; unsupported
ImageGrab/display capture is an explicit skip, not rendered proof. Other tests
cover actual paging, rapid wheel admission, metadata insertion/deletion,
non-symmetric reordered focus and cancellation while a page worker is active.
Only exact-checkpoint hosted results can qualify these changes.

No TeX manual impact: opt-in renderer implementation/verification and existing
interaction preservation; the default user controls and account flow remain
unchanged. No local execution, generated manifest edits, merge or release.

## Hover rearming, complete inventory and standalone stress

The expanded [53bc9374 check](https://github.com/klusik/PHP_gallery/actions/runs/44036821987)
was not qualified: Linux exposed two hover activation failures and Windows did
not finish its isolated 128-test viewer module before the existing 90-second
child limit. Zero reported Windows assertion failures did not mean complete
coverage. No test or process deadline is raised.

The hover repair retries the existing pointer-validated tooltip schedule when
a same-identity Motion arrives without an active Canvas tooltip state. This
covers an initial schedule rejected during pointer warp/coverage changes;
active pending/visible help keeps its existing timer. Assertions and timing
remain unchanged, with bounded failure-only pointer/identity context.

Four new Canvas integration cases move to
`test_native_gallery_canvas.py`, reusing the existing real-Tk fixture methods
without inheriting or duplicating native test methods. The original module
retains 124 tests, the new module contains the same four moved tests, and total
inventory remains 1452. Existing ordinary discovery assigns the new module;
no timeout, scanner scope, assertion or custom test registry changes.

The next protocol selection is a **standalone control stress** at 5000 photos,
cold/warm with instrumentation on/off (four scheduled children), using the
same registered audit/driver/harness and optimized control A. It is not the
three-extent primary matrix. A timeout remains FAIL and does not block primary
P0/P1/G3; restore the primary 100/500/2000 matrix for formal comparison. A
separate candidate stress follows, without manufacturing a numeric ratio from
a censored control child.

Post-run report export uses the driver's existing paired comparison data to
write PNG/SVG and a Czech Markdown table of all metrics. Matplotlib is pinned
and installed only after timed child processes finish. No bootstrap or other
statistics are recomputed by report rendering, and undefined ratios are N/A.

No TeX manual impact: internal test organization, opt-in hover parity and the
already requested stress/report profiles, with unchanged default user flow.

## Standalone control stress outcome and candidate follow-up

[Control stress 44043059258](https://github.com/klusik/PHP_gallery/actions/runs/44043059258)
at source `72b390284c3783552335e76372fd3f473f7a39cb` is **FAIL 0/4**:
the first 5000-photo cold/instrumented child hit the existing 80-second catalogue
deadline. The failure snapshot shows 2752 loaded metadata photos and 43 real
page reads; the primary measured counters were never finalized. No scroll
sample, completed full catalogue or numeric improvement is claimed. Hardware
was EPYC 7763; the main 60/60 cohort remains separate.

At the same source, Linux and coordinator checks passed. Windows completed all
124 original viewer tests but another remainder partition (647 expected tests)
hit its unchanged 90-second limit without a report: only 805/1452 tests were
reported, so the entire Windows run remains unqualified. This does not identify
a particular stalled test or justify raising any limit.

The candidate stress profile pins the actual Canvas application source above
and reuses the same existing four-child stress protocol. It is descriptive and
separate from the main statistical comparison. The next small UI/test change
also initializes logical focus if the Canvas received focus while the catalogue
was empty, without moving focus/scroll, and expands real Home/End/PageUp/PageDown
and modifier-key assertions. No stale source result qualifies that next source.

No TeX manual impact: internal focus readiness and qualification profiles;
default controls, account permissions and installed behavior remain unchanged.

## Final primary qualification profile

[Candidate stress 44046088721](https://github.com/klusik/PHP_gallery/actions/runs/44046088721)
completed **4/4** cold/warm on/off processes against Canvas application source
`72b390284c3783552335e76372fd3f473f7a39cb` (workflow source
`9d7f71aef9eedb71f6b129d1b1db1c009a05e285`). It verified the complete
5000-photo catalogue, 50 interactions and lifecycle with 13 native widgets.
The control's timeout and different host prevent a valid numeric stress ratio.
This earlier-source descriptive result does not qualify subsequent code.

The paging regression now waits for the complete automatically filled catalogue
and verifies both actual request cursors. It no longer requires the viewer to
pause in a transient two-photo/idle state before invoking a private load call:
existing production admission deliberately loads the next page when the first
two photos cannot fill the viewport. All wheel, keyboard, identity and resource
assertions remain intact.

Restore the main **100/500/2000** protocol, stage `compare`, without the
pilot-only orientation property. The final measurement is ten balanced AB/BA
process pairs in each cold/warm cell, 120 complete children, against immutable
control A. The existing workload/harness/driver, dependencies, deadlines,
raw-event limits and statistical definitions remain unchanged. Source checks,
Windows/Linux and frozen validation must pass at the exact candidate before
claiming qualification. Physical display and screen-reader acceptance remain
unverified; retain the default native accessibility path. Actual final outcomes
belong to the linked issue/PR and immutable generated workflow artifacts.

The compare workflow is dispatched only after current required functional
checks pass. Its post-run export writes all precomputed metrics to Czech
Markdown and seven GUI/resource delta panels to PNG/SVG. No local execution,
statistical recomputation, generated manifest edits, protected merge or release.
No TeX manual impact: internal test precondition and experiment/report profile;
default user controls and permissions are unchanged.

## Primary comparison launch checkpoint

At `8aeab05c013b4404fd409d8d6490e489ae8b7ba6`, [source checks](https://github.com/klusik/PHP_gallery/actions/runs/44048149708) passed and [functional/frozen validation](https://github.com/klusik/PHP_gallery/actions/runs/44048149681) completed: Windows 1447 passed / 6 skipped, Linux 1288 passed / 165 skipped, all 1453 tests accounted for, coordinator PASS and real frozen executable/installer PASS. Advisory macOS remains 1269 passed / 7 failed / 13 errors / 164 skipped; no macOS or physical-display acceptance is claimed.

The workflow is feature-only and does not currently expose manual dispatch on
`main`. The next marked ordinary feature checkpoint changes only this factual
document and workflow comments to request the existing push-triggered primary
comparison. Application, tests, harness, protocol, dependencies, timeouts and
statistics remain identical to the checked source above. The new exact event
SHA receives its own BASIC, required native/frozen and 120-child A/B results;
no result is inherited merely from tree similarity. No protected branch write,
merge, squash or release is authorized. No TeX manual impact: internal execution
checkpoint with unchanged user flow.

## Canvas negative-path coverage checkpoint

The code-identical `0c84e319db2768319fbf6d8fdf2f128bd6ad734d` passed
[BASIC](https://github.com/klusik/PHP_gallery/actions/runs/44049355632) and
[required Windows/Linux, coordinator and real frozen build/installer](https://github.com/klusik/PHP_gallery/actions/runs/44049355483).
A final coverage review found three feasible gaps in the Canvas-enabled viewer
fixture. Add focused cases for a failed real thumbnail read (unavailable
placeholder/help with a successful sibling), live `can_read_media` revocation
(closes/cancels before another media request), and a real right-click forwarding
the exact current photo descriptor without opening detail. Preserve the original
four Canvas integration cases and all 124 native viewer cases; reuse the same
fixture, ordinary discovery and existing deadlines.

The context test verifies target dispatch, not the contents or invocation of
PhotosWorkspace menu actions. Physical presentation, screen-reader/installed
acceptance, alternate OS DPI settings and an extended single-session soak remain
unverified. The default native renderer remains the accessible fallback.

Only tests, this factual document and an execution-owner comment change. The
application, harness, primary protocol and statistical definitions stay fixed.
The following exact-SHA native/frozen and comparison runs own qualification;
an incomplete superseded comparison is never accepted as a statistical PASS.
No local execution, test-registry change, deadline increase, protected write,
merge or release. No TeX manual impact: internal negative-path coverage with
unchanged default user behavior.

## Frozen primary comparison and archived readout

The registered central audit completed **120/120 compare processes** for the
candidate `5e9a050df977dae9423096e4df77864c625b8441` against baseline A
`ab245719ddf65ccba51a83ac10564a795a03dee9` in
[workflow run 44052468689](https://github.com/klusik/PHP_gallery/actions/runs/44052468689).
The run used an EPYC 9V74 host and retained artifact
[14317966343](https://github.com/klusik/PHP_gallery/actions/runs/44052468689/artifacts/14317966343)
(SHA-256 `6d4791dd65bac19b8369972c81882b4637df6c9b031525aa8c3a4060711ea102`).
This records the central audit's completed process set; it does not assert a
performance improvement or physical presentation latency.

The post-run job-summary publication exceeded its 1 MiB limit after the driver
wrote both its complete Markdown summary and concise JSON. The archived
`summary.md` remains the precomputed comparison readout. A diagnostics-only
reader may expose that Markdown from this exact owned artifact; it does not run
benchmark children, recalculate statistics, or qualify its own candidate. The
original full JSON, raw records, and raw events remain in the artifact.

The permanent protocol remains the primary profile: stage `compare`, baseline A
above, extents 100/500/2000, cold/warm caches, and ten matched process pairs per
cell (120 compare children). The earlier one-artifact diagnostics selection and
`diagnostics_only` flag are temporary readout configuration and are not part of
the default protocol. The separate 5000-photo stress remains nonblocking for
this primary comparison.

## G4 adoption gate outcome

The central audit passed all 120 scheduled compare processes. The stored
`scroll100/cold` cell nevertheless violates the adoption guard for both settled
wheel metrics. These are means of per-process p95 observations; the intervals
are the existing paired bootstrap 95% confidence bounds for B − A, with ten
matched process pairs:

| Metric | Optimized control A | Canvas candidate B | Existing paired 95% CI for B − A |
| --- | ---: | ---: | ---: |
| `wheel_slow_settled_p95_ms` | 46.855 ms | 78.149 ms | [22.277, 40.705] ms |
| `wheel_fast_settled_p95_ms` | 26.608 ms | 43.017 ms | [11.994, 22.815] ms |

Both regressions exceed the stated 10 ms and 20% G4 guard. The result is
**NO-GO for general/default Canvas adoption**. The native renderer remains the
default and Canvas remains opt-in. This result does not measure physical display
presentation latency or establish accessibility acceptance.

No TeX manual impact: this records an internal performance decision; it does not
change user-facing workflows, controls or product behavior.
