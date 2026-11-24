# WinApp Tk responsiveness phase 2

[Issue 434](https://github.com/klusik/PHP_gallery/issues/434) tracks the
baseline, measurements and decisions for Watcher presentation, Import
progress and Lightbox rendering. The immutable B0 application is WinApp
1.5.4 at `072dbf2fb824342c90110891b4612c9a30202c88`. Treat results from
earlier protocols as historical context. The current workload definitions
are recorded in [the Phase 2 protocol](../../tools/perf/issue_434_tk_protocol.json).

## Hosted execution

GitHub Actions checks out the exact workflow event SHA, verifies the selected
reference commit in its ancestry, and runs the central
`watcher-tk-performance` suite on `windows-2025`. An explicit
`[tk-phase2-pilot]`, `[tk-phase2-baseline]`, `[tk-phase2-compare]` or
`[tk-phase2-final]` feature push selects the run. Workflow dispatch supplies
the same profile where available. Ordinary commits receive the existing BASIC
source feedback. The historical 361 and 399 protocols keep their original
selection and validation.

The existing Issue 361 WinApp functional workflow also accepts Phase 2
`[winapp-full]` pushes. Its exact-SHA Windows and Linux WinApp suites and
coordinator checks retain their reports. macOS remains advisory. A
`[tk-phase2-final]` push with `[winapp-full]` also requests the central
isolated Windows frozen executable and installer build.
The progress-widget boundary fixtures use an exact synthetic monotonic clock
to verify whole-second labels and real progress timestamps consistently.

The pilot covers representative scenario and cache combinations once to
verify execution. Baseline covers all cells at the fixed B0 source before
changes to the application. The comparison and final selectors read
`.github/issue-434-comparison.json` when present on the selected commit.
Intermediate comparisons select phase-tagged workloads. Final comparison
selects the complete matrix. Source and benchmark code have separate
identities. Each child imports the same harness and one extracted production
`winapp` tree. Baseline and candidate execute sequentially on one VM per
case, cache and reference. AB and BA order alternate across pairs.

The fixed seed is 434361399. The workload hash covers the selected
configuration and seed. The harness hashes cover the driver, child, the
shell and media adapters and reusable #361 modules. Each independent
process starts with a new isolated profile. Warm-cache priming runs before
timed work. The cold designation describes the application cache in a fresh
process and cannot establish the host operating system's disk-cache state.

## Workloads and gates

The matrix includes one-minute idle, Dashboard, steady/burst Watcher, a
minimized/background shell case, Activity filters, 100/1,000/5,000-item
Import, a 5,000-job history fixture, 100/500/2,000/5,000-photo catalogues,
regular and rapid Lightbox input with 1920×1080, 2560×1440, 3440×1440 and
3840×2160 source rasters, Photos combined with Watcher and Import, and a
three-minute session. Virtual display dimensions and DPI are captured
independently. Timed windows of 8.5–20 seconds for interactive cells cover
multiple actions without turning each regular checkpoint into a stress run.
The configured input cadence varies by surface and includes a 20 ms
rapid-input interval and slower Import progression. It is workload input
cadence, independent of Tk or worker scheduling.

The primary design uses ten separately started processes per B0 cell and
ten AB/BA pairs per comparison cell. The long session reports three repeats
as a resource trend. Use the extended selection of twenty pairs for material
variability. The paired confidence interval uses the existing deterministic
95% bootstrap of the mean of per-process differences, while the report also
shows the paired median and individual observations. Treat every failed
process as absent evidence with a recorded reason.

The latency margin is the greater of 5 ms and 15% of the baseline process
median for the selected primary tail metrics. Import throughput tolerates
10% before its regression guard. Investigate a peak or steady RSS increase
above the greater of 128 MiB and 30% of baseline. A 95% interval above the
applicable worsening margin flags a regression or memory investigation.
An interval crossing a margin is inconclusive. Review absolute stall
tails, missing metrics and correctness coverage separately. The
[issue](https://github.com/klusik/PHP_gallery/issues/434) records the target
latencies, structural goals and safety invariants.

## Evidence

Each job retains original audit and workload records with stdout/stderr,
runner image and hardware, exact SHAs, setup and timing metadata and the raw
events associated with a case. Collection verifies the selected matrix
against the actual run and source identities. It publishes a compact summary,
the protocol and long-form process/metric CSV. Individual cell artifacts keep
raw event JSON and any pilot profile data. The Actions artifact retention is
sixty days.

The collector verifies the exact tracked protocol with either Git newline
encoding seen across Windows and Linux. For a reporting-only failure in a
complete B0 run, the `recover-baseline` profile recollects original artifacts
using their provider run ID, production source and harness SHA. It requires
every original Windows cell job and each planned process identity. The
result records both the original measurement and recovery run identities.

The final profile builds the offline Czech report, SVG figures and evidence
ZIP from hosted artifacts. The normal B3 comparison exercises this same
report pipeline and labels its output as a preview. The configuration lists prior
checkpoint run IDs and explanation text. The bundler authenticates their
source identities and retains the matching functional workflow outcomes and
central audit reports for the final SHA. The report distinguishes advisory
macOS from required Windows, Linux, coordinator and final packaging jobs.
It retains each cell's measurement attempt when a rerun reuses
exact cells from the same run. GitHub Actions captures desktop and narrow
views of the standalone HTML for review. Missing measurements or failed
checks stay visible in the report.
Raw curves bind each process to its own measured run, attempt and reference.
The long-session plot uses only the long-session trials. Signed deltas are
candidate minus reference in the charts and tables.

The independent child counts successful Tk assignments and selected
configuration, widget state and geometry calls. Repeated observed
assignments compare digests for the same Tcl variable during the measured
window. The ledger is limited to 10,000 names to bound its memory. This
counter cannot see direct Tcl writes or prove whether an assignment to a
different value invalidated other UI work. The state probe distinguishes
history listing, JSON decoding and private file reads by thread. Control and
candidate get identical probes.

The GUI metrics describe software feedback and Tk scheduling. A hosted
virtual display cannot establish physical monitor presentation, real device
input latency or installed-user acceptance. A minimized shell workload
exercises the app's background work during withdrawal and restoration.
Native Windows tray interactions require separate coverage. The experiment
is separate from functional, installer and protected integration checks.

The Watcher follow-up pairs changes to the diagnostic log tail against
the pre-change checkpoint and B0. Durable logging preserves every ordered
write. The Tk text widget appends the visible tail once per event drain.
The hidden tail retains the configured Activity history bound.
The saturated Watcher experiment compares shorter cooperative drain slices
and reports both offered and consumed events per measured second. Its
fixed-arrival workload can exceed the service rate and leave a backlog.
An independent Activity variant formats the footer only for the published
event and avoids copying the retained history on each append. It times the
same FIFO and durable Activity sink.
The next variant only wakes that writer on an empty-to-nonempty transition.
Its existing timed batch window, ordered append and close flush stay intact.
The continuation interval experiment varies the delay after a saturated
batch from 16 to 24 ms. The event count and time budgets, the 200 ms empty
poll and the ordering of all processed events stay fixed. The selected
24 ms delay improves measured warm burst responsiveness with a lower service
rate under synthetic saturation. Steady Watcher latency and throughput
remain within the observed bounds of the 16 ms control.

## Import progress measurements

The Import worker assigns a new revision after the job store completes its
durable save and readback. A revision carries counters and only the changed
rows within the existing item cap. Start, cancellation, completion and a
revision gap use a complete bounded projection. One UI drain merges ordinary
visual updates, with a target interval of 100 ms while Import is active.
Opening an affected pane or receiving a terminal transition publishes its
current state immediately.

The compact upload-state experiment compared the same logical JSON data with
and without pretty-printing during normal job saves and Activity appends. Both
routes retain the accepted-work owner, atomic replacement and readback. The
targeted hosted selection includes long-session, concurrent Import/Lightbox,
large standalone Import and Watcher burst against B0 and the first BF SHA.
The probe times entry to the owner context, its protected body and each
atomic state write by GUI or worker thread. Owner entry can include native
file and directory checks as well as lock acquisition. Protected-body time
ends before context cleanup. Both source legs use the same instrumentation.
The Windows probe recognizes the temporary profile both as created and as
resolved. The isolated readback exercises both reference legs again when an
alias prevents earlier timing samples from matching.
The full long-session pairs against the first BF supplied no reliable
throughput improvement from compact encoding. The original write format was
restored. The long-session driver schedules the next input after the previous
action returns. A more responsive UI can execute more Watcher and media work
over the same fixed duration, so interpret the confirmed Import rate together
with completed steps, offered and consumed events, and state-owner timings.

Activity requests a bounded history list and selected details on a single
background reader. List refresh and explicit selection have independent
latest tickets. A read result adopts a separate selected job model for
explicit actions. The write owner and recovery path keep their accepted-work,
destination and receipt checks.

Paired Import comparisons observe the time from progress enqueue to takeover
by the existing Tk event pump and time/count of successful progress text
writes. These describe distinct stages. The workload feeds the same ordered
synthetic outcomes through the production save path for each SHA. The
state probe times both the original full-list entry point and the bounded
history entry point and continues to count JSON decodes by thread.

Cadence experiments use a real feature SHA with the changed timing policy.
They pair the specified Import or Lightbox cases directly with the
corresponding ordinary checkpoint on the same Windows VM.

## Lightbox publication measurements

One raster worker belongs to each Tk interpreter. A Lightbox transfers
its source to that worker, which detects the black matte and prepares
bounded Pillow rasters. A source replacement fences old results before
they can reach Tk. The active resize can finish while one replaceable
request follows it. Both the source owner and the current request identity
are checked before publishing. The viewer also checks its live media
session and clears the overlay on withdrawal of access.

Zoom and resize use BILINEAR previews while input continues. A trailing
LANCZOS pass renders the settled viewport with the original pixel cap.
Tk retains a Canvas photo item, positions or replaces its Tk image, and
maintains a separate status item. Toolbar changes update HDR occlusion
using the current displayed crop and prepare a new raster only if the
viewport geometry changes.
The slideshow starts the next interval after the first displayed frame or
visible retrieval error. A replaced or revoked request cannot advance it.

Paired runs time viewport request entry through successful Tk Canvas
attachment and positioning. Preview and final requests retain separate
latency distributions. The earlier callback-duration proxy remains
historical. Candidate HDR updates have their own attachment outcome,
and the provider's virtual display limits physical presentation evidence.
The regular and rapid input workloads record their event-loop feedback
separately from raster publication.
The cadence experiments first vary preview, final debounce and result poll
together, then vary only the active result poll after observing a tail cost.
The independent poll comparison keeps the input schedule, image quality,
render bounds and other WinApp changes identical in each pair.
The selected setup schedules previews at 33 ms with a 90 ms trailing render
and checks the active result every 8 ms. The isolated poll comparison improves
rapid-input preview publication and event-loop tails. Regular settled raster
publication still includes the asynchronous worker and Tk attachment.
