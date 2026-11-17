# Tkinter and Canvas performance protocol

This opt-in experiment measures the current Watcher interface through
`php scripts/audit.php --suite=watcher-tk-performance`. The issue-scoped
`Issue 361 Tk performance` workflow owns execution on GitHub Actions.
A feature commit message beginning with `[tk-measure]`, or manual workflow
dispatch, selects hosted measurement execution. Historical markers inside a
squash body do not request another measurement. Ordinary production checkpoints
use automatic BASIC and functional CI without replaying the frozen baseline.
The functional workflow retires older functional and benchmark runs only on
its explicitly owned feature branch and exact workflow IDs. Cleanup excludes
the current run, newer runs, same-SHA measurements and every other branch.
The central audit remains the only orchestrator. It grants no protected
integration or release authority.

## Source and workload identity

The pre-optimization application source is
`362361414b78a2715379ec5f805c2f2aa677dc37`.
`tools/perf/issue_361_tk_protocol.json` selects the stage and immutable
`baseline_sha`. The driver, workload implementation and configuration are
versioned together. Freeze their exact successful baseline revision before
optimizing; later workload or instrumentation changes invalidate comparisons.

Each measurement starts a separate application process with an isolated
temporary profile and cache. Both sources in a comparison use the same
harness bytes, Python/dependencies, synthetic data and operation order on one
VM. Source archives are obtained from exact committed Git objects on the
remote runner. Production accounts, galleries, credentials, network requests
and live SimConnect are excluded.

The four workload cells are dashboard/Watcher, import, Photos and lightbox.
They invoke production callbacks and existing Tk owners. Domain adapters
supply deterministic synthetic events and media; they do not replace GUI
implementations. Import admits 1,000 items in each existing item table plus
bounded history/Activity data. Photos supplies exactly 500 unique synthetic photos
and thumbnails with existing viewport admission. It scrolls and resizes,
refreshes unchanged and changed metadata, switches gallery and reopens the
original gallery while retaining the existing bounded shared cache. Lightbox uses synthetic
high-resolution media and exercises zoom, pan, resize, fullscreen, rapid
switching and obsolete-result cancellation. Scenario completion is required;
a missing display or failed required action is not a valid timing sample.

## Configuration policy

The JSON schema version `1` identifies this report/protocol layout. It is a
format discriminator, not a performance limit. Operational defaults are:

| Owner / value | Units and scope | Consumer and rationale |
| --- | --- | --- |
| Seed `3612026` | Seed per workload process | Synthetic data generation repeats the same workload across sources. |
| Pilot `1` run per instrumentation cell | Independent processes per scenario/cache/on-off cell | Driver validates the complete matrix before expensive replication; overhead remains descriptive. |
| Baseline `10` runs; comparison `10` pairs | Independent units per scenario/cache | Driver satisfies the requested minimum; increase when variance requires it. |
| Bootstrap `2000` resamples | Resamples per paired metric/cell | Statistics bounds deterministic analysis cost while estimating a 95% interval. |
| Child `90` seconds; driver `2400` seconds | Wall-clock deadlines per process/stage | Driver terminates hung scenarios and bounds total hosted occupancy. |
| Workflow `45` minutes | One Windows job | Actions allows the 40-minute driver deadline plus setup and artifact upload. |
| Artifact retention `14` days | Exact-SHA run evidence | Actions keeps raw review evidence for two weeks without indefinite duplication. |

The fixed runner/runtime/package pins above describe the benchmark
environment; they do not modify the application's dependency requirements.
The workload file owns and documents its operation counts, image dimensions,
probe cadence and per-action deadlines. Do not silently change those values
between baseline and candidate.

## Experimental sequence

1. `pilot`: validate every scenario/cache cell and observe instrumentation
   enabled/disabled wall times. A short pilot overhead observation is
   exploratory and does not establish statistical equivalence.
   Optional `pilot_scenarios` selects a canonical subset for focused diagnosis;
   its report states `targeted: true` and `pilot_complete: false`. A targeted
   success does not replace the complete pilot before baseline collection.
2. `baseline`: collect at least ten independent processes per scenario and
   cache regime, excluding deterministic warmup from measured raw timing records. Publish the exact source,
   harness revision, successful Actions run, metadata and raw artifacts to
   issues #361 and #397 before changing performance-sensitive production code.
3. Functional qualification: after each production checkpoint, run targeted
   central WinApp regressions remotely. Final relevant regressions must pass
   before the comparison stage.
4. `compare`: at least ten independent pairs per scenario/cache cell on the
   same VM. Alternate AB and BA pairs, giving ABBA across adjacent pairs.
   Preserve the original control source; record candidate and harness SHAs.

Cold means a new process and empty application/image cache for the
experiment. It does not guarantee a cold operating-system filesystem cache.
Warm means deterministic priming of the same data and caches in that process
before measured actions. Lightbox priming first waits for visible grid thumbnails,
then opens the fixed detail indices `0, 1, 0` through actual bounded cache
admission, waiting for each current image before continuing. This avoids waiting for grid work that foreground
detail selection intentionally cancels. A priming failure invalidates the process.
Gallery scroll input starts after initial metadata admission, so every process
executes the same twenty actions and five resize requests. Lightbox executes
twenty complete zoom/pan/fullscreen/resize macrosteps and forty rapid selections
from a fixed two-photo working set. Readiness polling queues no user input and
produces no interaction-feedback samples; the last selection burst must settle.
Never pool cold and warm results. Increase replication
when observed variance makes a result inconclusive; do not continue expensive
runs after a fault invalidates their inputs.

An optional `diagnostics_only` configuration imports selected retained Actions
artifacts on the hosted runner without executing a workload. Each
`diagnostic_artifacts` descriptor binds its artifact ID to the original run,
source SHA and evidence kind. Inspection never qualifies the current candidate
or turns a failed experiment into a measurement PASS. Clear the inspection
inputs before collecting a new pilot or baseline.

## Measurement meaning and validity

Use `time.perf_counter_ns()` for elapsed durations. Warmup timings are
intentionally excluded from raw retention so they cannot exhaust the measured
record budget. Preserve measured raw callback, widget-operation and image-stage
observations where available. The 20,000-observation per-process limit applies
only to measured timing records; any actual measured overflow invalidates the
process. A dedicated 16 ms Tk heartbeat measures dispatch
lateness against each newly scheduled deadline; it does not accumulate previous
lateness or mix idle callbacks into the drift distribution. General `after`
dispatch delays remain a separate diagnostic field, `dispatch_delay_ns`, in
the same callback record as `callback_name` and elapsed `ns`. This retains the
complete observations without three duplicate rows per scheduled callback.
Report scheduling drift, callback completion, application feedback, worker
enqueue-to-consume, raster preparation and Tk image publication independently.
Widget operation counts are not a physical frame count.

Callback return, Tk update completion and render submission do not prove
physical monitor presentation. Hosted screenshots can establish widget
content/geometry only within their recorded display configuration. Physical
click-to-visible latency and flicker elimination remain BLOCKED without an
adequate remote display/presentation measurement method; no owner-side local
qualification is required by this experiment.

Record the actual image label/version, Windows build, CPU model/logical cores,
RAM, Python/Tcl/Tk, Pillow/psutil, display backend/resolution/DPI when available,
cache mode, workload seed, exact application and harness identities, and a
genuine UTC timestamp. The default software pins are Windows image label
`windows-2025`, Python `3.14.10`, Pillow `12.0.0` and psutil `7.1.3`.
The label does not pin physical hardware or the rolling image version.

A cell is PASS only when all its required independent observations are valid.
Preserve FAIL, BLOCKED, NOT TESTED and unavailable individual metrics explicitly.
Do not manufacture zero durations or empty successful samples for missing
instrumentation. Keep partial evidence when a subprocess fails.

## Statistical unit and evidence

Keep scenarios, cold/warm regimes and incompatible hardware configurations
separate. Summaries include mean, median, sample standard deviation, p50,
p95, p99, minimum and maximum over independent run-level values. Event-level
tail values may be reported within a run, but individual events are not
independent replication units.

Comparisons report absolute and percentage candidate-minus-control changes,
baseline/candidate ratios, and deterministic paired bootstrap 95% intervals.
Resample independent paired runs rather than pooling all GUI events.
Report regressions and long stalls even when the median improves. Provisional
targets from #361 are application-feedback p95 <=100 ms, scheduling-drift
p95 <=30 ms and p99 <=100 ms; they are evaluation goals, not promises of
physical-screen latency.

The driver retains `report.json`, raw JSON/CSV and `summary.md` below the
audit run's `issue-361` directory. Actions uploads available audit output even
on failure. Audit PASS is experimental measurement evidence, separate from
BASIC, functional WinApp coverage, FULL qualification, frozen packaging,
installed-client acceptance and publication.

## Functional and frozen coverage

The separate feature workflow runs the complete central Windows partitioned
inventory and Linux inventory under Xvfb; macOS uses the complete partitioned
inventory after an observed discovery timeout and remains advisory
with actual failures and skips. Its execution gate for a real frozen/installer
build is `.github/issue-361-validation.json`, separate from this frozen
measurement configuration. `winapp-frozen-installer` invokes the existing
builder in an owned temporary copy and retains its real runtime, help and
updater-helper smoke output plus installer/metadata. Installer installation,
installed GUI behavior and uninstall are not tested by that build.

## Original frozen calibration checkpoint

The calibrated full pilot passed all sixteen process observations at harness
revision `657ba64b9c2356c142bea8cd07fc3f1939d7cea5`:
[hosted run](https://github.com/klusik/PHP_gallery/actions/runs/43956736647),
[raw artifact](https://github.com/klusik/PHP_gallery/actions/runs/43956736647/artifacts/14271743235).
Its workload SHA-256 is
`e2796d3ac0f0f1ca2e25a12b3ef98954e5ca1e97a3eb3663a310b0ffa9cf7ca4`,
driver/statistics SHA-256 is
`2eb773ee831f93680be72d3e095e7d0a959dcbe61b36fbc8b7297355045f2fe3`,
and semantic protocol SHA-256, excluding only the stage selector, is
`316afd2898aa11c70f4f817423454766522ac6c749074cf42773ff00e96975b2`.
Baseline and comparison must retain those bytes and semantic settings; a
harness change requires a fresh baseline before performance claims.

The one-pair-per-cell overhead table describes whole-process wall time,
including startup and fixture generation. The first dashboard/cold process
showed 20.131 percent; other cells ranged from -3.247 to 4.033 percent.
These exploratory values do not isolate probe cost or establish statistical
equivalence. Measured steady-state windows exclude warmup; subsequent paired
results describe the instrumented workload with this limitation retained.
This pilot is calibration evidence, not the ten-process numerical baseline.

## Original accepted numerical baseline and implementation scope

The numerical baseline passed all eighty process observations at experiment
revision `03bdf58070e5a3fb684d642b318347e6de442b0b`, with ten independent
processes per scenario/cache cell. [Hosted run](https://github.com/klusik/PHP_gallery/actions/runs/43957959744)
and [raw JSON/CSV and complete statistics](https://github.com/klusik/PHP_gallery/actions/runs/43957959744/artifacts/14274751211)
were published to [issue 361](https://github.com/klusik/PHP_gallery/issues/361#issuecomment-6929991612)
and [issue 397](https://github.com/klusik/PHP_gallery/issues/397#issuecomment-6929992065)
before production optimization started. All three frozen hashes matched the
accepted calibration. The VM had four logical cores of an AMD EPYC 9V45,
17,174,360,064 bytes RAM, Windows build 26100, Python 3.14.10, Tk/Tcl 9.0.4,
and a 1024 by 768 display at approximately 96 DPI. Its image version was
20261103.298.1. Do not compare these absolute times with another VM as a
candidate effect; P3 repeats the control and candidate together on one VM.

The following values are medians of independent process-level p95 values,
in milliseconds. Cold and warm observations remain separate.

| Scenario | Feedback cold / warm | Dispatch drift cold / warm |
| --- | ---: | ---: |
| Dashboard | 1.142 / 1.104 | 14.791 / 14.709 |
| Import | 72.313 / 71.219 | 50.970 / 51.998 |
| Photos | 126.295 / 178.523 | 173.710 / 116.684 |
| Lightbox | 113.884 / 106.553 | 238.915 / 232.008 |

Import produced 164,000 to 166,000 Tk row insertions across twenty actions,
and dashboard activity also rebuilt thousands of rows. Photos constructed
2,042 widgets in the cold process and 1,528 in the warm process. Lightbox
GUI raster resizing occupied median totals of 2.53 to 2.65 seconds per
process; image-constructor p95 was approximately 2.1 ms. These observations
justify incremental row reconciliation, same-ID/order tile reconciliation,
and removal of a duplicate synchronous fullscreen-exit repaint. They do not
justify a transport, cache-capacity, worker or rendering-framework rewrite.

The exact experiment revision passed BASIC and the complete required Windows
and Linux functional inventories. macOS remained advisory with explicit
cache/update/platform test failures. Physical presentation, flicker and
installed-client behavior are outside the measured qualification.

## Candidate rendering responsibilities

The Watcher UI owns incremental table row projection and reconciliation.
Domain filters, durable job lookups, retry/recovery decisions and persisted
activity remain in their existing owners. Stable visible row identities avoid
rebuilding unchanged rows; surviving selection and focus stay attached to the same rows, scroll fractions
are retained across structural changes, and removed rows are not replaced by
automatic selection.
The existing visible limits remain 1,000 items per item table, 200 jobs and
100 filtered activity events.

The Photos controller reuses tiles only for snapshots with identical ordered
photo IDs. It retains the existing descriptor comparison for image reuse and
invalidation, cancellation fences, decoded cache limits and viewport admission.
Membership and ordering changes continue through full reconciliation. The
lightbox keeps its synchronous final rendering and existing preview/debounce
owners, with only the duplicate fullscreen-exit repaint removed.

Required functional checks and the optional real frozen build precede the
paired candidate comparison. A built installer provides runtime/help/helper
smoke evidence; installation, installed GUI and uninstall remain untested.

The comparison issue summary exposes paired mean changes, percent changes,
ratios and paired bootstrap 95 percent intervals alongside independent-process
means, medians and p95 values. Dispatch p99 and maximum drift/feedback metrics
remain visible so a median improvement cannot hide long stalls. Full sample
standard deviations, p50/p95/p99, minima, maxima and raw observations remain
in the unchanged driver artifacts. This is a publication view of the frozen
statistics, with no measurement or sampling change.

## Linear controller checkpoints and renewed measurement identity

The first paired run at `2f92c60503d2690a47fdbb83ae912866b4f55e03`
([run 43979228202](https://github.com/klusik/PHP_gallery/actions/runs/43979228202))
reached the unchanged 2,400-second outer deadline. Its unfinalized report remains
FAIL. [Authenticated artifact inspection](https://github.com/klusik/PHP_gallery/issues/361#issuecomment-6933672198)
found 159 saved process records, 426,262 raw observations and no declared child
failures, missing required metrics or truncation among them. Only the last
baseline/lightbox/warm process is absent. Saved child intervals totaled
2,166.8 seconds; 284.2 seconds of the overall workflow lay outside those saved
intervals, including setup, export and any unrecorded child. These partial data
are diagnostic and cannot qualify a comparison.

The controller previously rewrote every prior raw JSON/CSV payload after each
child. Incremental persistence appends only each newly completed process to
raw CSV files and a compact `progress.jsonl` ledger. Per-child JSON, stdout and
stderr remain durable. The existing complete final export and final status
validation remain authoritative. Progress rows are checkpoints, never a
qualification result. This changes controller work outside child measurements;
workload actions, probes, sampling, metric validation, statistics/bootstrap and
all execution deadlines remain unchanged.

A changed driver identity invalidates comparison reuse of the original frozen
driver above. Require a complete new pilot and eighty-process original-control
baseline, then publish the replacement identities and results in
[issue 361](https://github.com/klusik/PHP_gallery/issues/361) and
[issue 397](https://github.com/klusik/PHP_gallery/issues/397) before repeating P3.
The old accepted P0 remains historical evidence that preceded application edits;
the failed P3 and its partial records remain unqualified. Do not pool their
samples into the replacement experiment.
