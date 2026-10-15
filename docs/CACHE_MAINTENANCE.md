# Cache inventory, retention and independent reclamation

Admin > Maintenance > Media and cache now exposes **Clean updater cache now**
above the two compact media groups. It runs one forced bounded slice in place;
**Continue cleanup** runs another explicit slice when debt remains. The visible
scope preserves thumbnails and download caches as well as originals and backups.
Compatibility settings and schedule diagnostics remain native disclosures, and
archive/renamer links are concise shortcuts. Non-JavaScript POSTs return to the
same Media tab. JavaScript preserves URL, focused maintenance tab and any open
panel, delegates dynamically injected forms, and passes the canonical mutation
envelope to the existing completion owner. This singleton cache operation has
no catalog entity IDs or affected public media render contexts.

Issue: [#212](https://github.com/klusik/PHP_gallery/issues/212).

## Persistent rotating regions

The automatic cache worker uses a persistent rotating registry in `cache_lifecycle_regions`. One row owns each safe top-level managed directory, plus fixed discovery, root-file and receipt identities; there is no row per file or per check. The model owns SQL and the migration owns schema. The service owns producer policy, paths, discovery and scheduling. Indexed due/retry times select the oldest due work; each committed partial slice yields for thirty seconds, completed healthy regions wait one hour, and failed regions back off for fifteen minutes. Last service and last successful completed check remain separate.

DB checkpoints are the only authority for regional stacks, catalog offsets, plan write/read fences and detached deletion cursors. Private filesystem journals hold catalog names, queued proof records and detached payloads. They do not contain a second independently updated checkpoint. A restart resumes the existing cursor; incomplete inventory never returns to its beginning merely because another opportunity arrived. Existing JSON manual/upgrade work is drained in its original location before registry initialization, including detached payloads; unreadable state blocks. The historical worker API remains for standalone database-free consumers and explicit manual previews. Retire this compatibility only after deployed unfinished legacy jobs and those consumers are migrated; usage is unknown.

Discovery runs as another rotating task with a durable root catalog. New directories update/insert stable SHA-256 path identities; root files share one bounded region. A complete readable discovery may mark a drained missing region absent; a partial/unreadable catalog cannot prove absence. Seven-day drained tombstones and their private journals are retired in bounded batches. The 4096-name catalog limit and 4099-row bound include three reserved identities; capacity overflow is visible and preserves unseen content. Reused paths receive fresh producer validation, never inherited deletion authority.

Passive Admin evidence retains the last completed regional measurements during later partial work and labels them as historical, with coverage and the oldest completion time. Smaller completed measurements replace larger ones directly; historical bytes are never reduced mechanically by deletion counters. Working observations, known pending debt and installation-wide confirmed removal receipts remain separate. Receipt updates and the deletion checkpoint commit share a DB transaction; filesystem deletion itself does not. Existing deterministic trash names and write fences recover a crash before/after detachment. A crash after unlink before checkpoint commit can conservatively undercount bytes, without double counting or granting permission to a replacement payload. OFF preserves all state. Status reads perform no filesystem scan and never create rows.


Passive lifecycle storage and producer leases remain separate from inventory
and reclamation workers. The reviewed runtime plan reuses the existing
`request-maintenance` closure only across selected site-maintenance, updater,
Admin test-recording and authentication consumers whose existing closures
already contain all four files. The authentication edge replaces four direct
duplicate entries without expanding its PHP file closure or adding a logical root.
The dashboard retains its existing module decomposition allowance.
GitHub cache writes explicitly load the lease owner before acquisition;
ordinary public cache reads do not load it.
Site-maintenance trigger registration also leaves lifecycle storage unloaded;
dashboard status loads its passive evidence owner explicitly, while actual
maintenance work loads the complete lifecycle worker.

The updater defers physical removal after twenty cleanup slices. Previously no
independent worker drained its remaining `cache/updates/prune-trash` queue.
Site maintenance now processes that **already detached, owner-approved** queue
before thumbnail enable/window/completion checks. Maintenance Center's existing
`downloads.cache` task uses the same owner first and continues until empty or
visibly blocked. Its analysis reads committed evidence without scanning a tree.

Configure hosting cron every minute:

```sh
php /absolute/installation/scripts/site_maintenance.php --cache-only
```

Normal cron also executes independently due GC. The existing token-protected web
cron supports `cache_only=1`; keep its URL and token private. Cache-only calls
skip telemetry, thumbnails and database cleanup. `--force` bypasses the throttle,
never updater or central Maintenance Center ownership. The existing eligible
after-response mechanism is traffic-dependent; guaranteed idle-time progress
requires a scheduler.

## Owner policy

| Storage | Independent worker behavior |
| --- | --- |
| `cache/updates/prune-trash` | Reclaim already-staged disposable updater data. |
| Updater jobs, selected backups, rollback/recovery state | Preserve; never stage live workspaces or backups. |
| Active updater pointer, including corrupt/unreadable state | Block deletion until reviewed through existing updater recovery controls. |
| Download manifests, immutable legacy ZIPs and indexed ZIP cache | Existing owners retain leases, reservations, retention and quotas; Maintenance Center still dispatches them. |
| Gallery Trash, uploads, thumbnails, logs, diagnostics and unknown cache entries | Preserve; location under `cache/` alone never authorizes deletion. |

Each independent slice admits 180 filesystem observations/attempts and 180 ms.
Blocking OS I/O may overrun the admission deadline; elapsed time is reported.
Directory descent, failed attempts and empty-directory traversal consume the cap.
The relative directory stack, capped at 64 nested levels, is atomically persisted
in `cache/updates/cache-gc.json`. Successful removals advance the queue itself;
resumption does not enumerate previously deleted entries or materialize a full
tree. Checkpoints and existing ancestors are revalidated. Links/junction redirects
cannot redirect deletion; internal symbolic links are unlinked without following
targets. Deeper trees and permission errors require review.

The updater's `start.lock` excludes new updater starts and other GC workers.
The legacy maintenance worker lock and central Maintenance Center claim are also
respected. Kernel locks recover after a crash; lock files are never removed.
Unfinished successful slices retry after 60 seconds; idle/blocked slices wait
900 seconds. Existing unreadable/oversized checkpoint state refuses processing.

Cron JSON exposes `cache_gc.value`: `done`, `blocked`, `visited`, `removed`,
`bytes_removed`, `total_bytes_removed`, `elapsed_ms`, `next_due` and safe reasons.
These are actual successful removals, not expired TTLs or estimated queue bytes.
A crash after deletion but before checkpoint commit can undercount reclaimed
bytes without compromising deletion safety. Checkpoint paths/stacks are private.
Maintenance Center displays cumulative actual bytes and current queue presence,
explicitly stating that queue size is not measured; execution records actual
per-slice bytes and visible warnings.

## Incident procedure and limits

### Manual-run diagnostics

Each copied updater checkpoint includes the server observation epoch, checkpoint
age, creation/start epochs, attempt count, operation/trigger and permitted actions.
An instantaneous nonblocking probe of the existing worker lock distinguishes
`held` from `available`, `missing` or unreadable/unsafe/unavailable evidence. It
opens no new file and changes no lock metadata; the probe releases immediately.
It is not an OS PID check or a lease, and another worker can start after the probe.
The compact label therefore does not equate persisted `running` with live work.

Successive visible samples record whether phase/counters changed, a same-phase
counter delta and seconds without observed progress. The first sample has no
delta and cannot prove a stall; counter resets are retained rather than disguised
as positive progress. Paused/hidden intervals do not prove continuous observation.
Retry retains the preceding owner-redacted error under `result.last_failure`;
copied diagnostics explicitly distinguish this history from the current error.
Old jobs have no invented historical error when it was previously cleared.

The disclosure includes **Copy log**, copying the complete currently displayed
JSON trace with visible success/failure feedback. Clipboard API rejection or HTTP
hosting uses the established selection/legacy-copy fallback. Delegation also
covers dynamically injected panels. Copying sends no additional server request.

A four-pixel progressbar shows an indeterminate bounded cleanup request, or real
current/total counters for the blocking updater's phase. It does not estimate
whole-cache percentages. Visible running-job checkpoints refresh read-only every
three seconds through the existing Admin Updates status endpoint; hidden pages
and hidden/detached panels do not poll. A failed status read is visible and stops
polling. Reads never continue, retry or advance updater work.

The startup maintenance hook explicitly yields for GET updater status reads and
the three cache POST actions. Legacy startup otherwise continued an active update
before the controller even for passive reads or a stop request. Ordinary routes
and settings saves retain their background triggers and all dispatch security
checks still run. A failed phase can have 100% observed entries; it is shown in
the failure color with the existing owner's safe error message/reference. The
latest read-only updater checkpoint is included in the copied trace and replaces
its previous checkpoint, never growing the trace on each poll.

**Stop update** is offered only when the updater owner reports cancellation is
available. The authenticated CSRF action revalidates the selected job/stage and
uses the existing start/worker locks and cancellation owner. It refuses activation
or later stages and unknown stages. A busy worker response does not claim success.
Confirmed pre-activation cancellation completes in place through the canonical
mutation envelope; cleanup can then be requested again. This controls a durable
updater job, not an operating-system PID. The existing cancellation owner may
spend time removing prepared artifacts; transport timeout does not prove failure
or authorize automatic retries. Later checkpoints remain authoritative.

The button immediately shows a non-secret 32-character diagnostic ID. The result
includes a bounded trace and a link to Admin Logs filtered by that full ID. Logs
use `cache.cleanup.*` event keys and record admission, worker loading, lock
ownership, checkpoint loading, bounded observations/removals, reclaimed bytes,
checkpoint commit and completion. A busy result exposes the exact reason and,
when a central maintenance claim owns the installation, its job ID and phase.
Force does not override ownership. Inspect the existing job before retrying.

`active_updater_pointer` means `cache/updates/active-job.json` is present even
though kernel locks may be free. Its diagnostic event includes validated job ID,
status, stage and last-update epoch, or an explicit unreadable/invalid marker.
These two read-only metadata reads share the 64 KiB GC cap. Completed/cancelled
pointers are marked for updater-owner review; the diagnostic never clears them.
Insertion receipt fields appear only in the returned UI/PHP error-log trace,
after insertion. Persisted Admin rows omit provisional receipt flags.

Each event reports whether its Admin log insert actually succeeded. The installed
`request_id CHAR(26)` column stores the ID prefix; structured context retains the
full searchable ID. Safe insertion failures expose SQLSTATE, driver code and an
approved log-column name, never raw SQL, credentials or private filesystem paths.
Every manual event is also sent to PHP's configured error log. Missing Admin
storage is visible in the trace; it does not turn deletion into a logging failure.

HTTP/JSON/network/timeout failures show the ID and a safe error code. The browser
attempts one authenticated report without retrying deletion. Network loss can
prevent that report too. Fatal PHP shutdown logging is best effort; process kills
or exhausted resources can leave only the last committed event. This trace is
request-local, not a live polling monitor; counters describe the bounded slice.
Cron retains its existing JSON/checkpoint evidence and does not emit this manual
per-stage trace on every idle tick.

1. Obtain read-only production category sizes before attributing WEDOS's reported
   988 GB to any owner. That number remains provider evidence.
2. Review Maintenance Center analysis and approve its existing cache cleanup task.
   It can reclaim already-approved prune trash without a broad delete-cache action.
3. Install cron and verify continuing observations/removals until `done=true`.
4. Investigate an active pointer with existing updater status/recovery controls.
   Do not blindly clear it or delete rollback backups. Repair permissions or
   unsafe paths before a forced retry.
5. Measure production before/after sizes and separately obtain hosting-provider
   confirmation; code or BASIC checks do not resolve the production incident.

This remediation covers stranded updater prune debt and independent scheduling.
Full resumable production inventory, preview-backed emergency reclamation outside
the already-staged queue, a registry for every producer, overall/per-category
admission quotas, and bounded legacy-download enumeration remain #212 follow-up
work. The existing 4 GiB legacy ZIP budget does not cap the entire cache.
Unknown data is never assigned an invented TTL or automatically erased.

The 2026-10-14 read-only local inventory visited 74,396 files without errors.
`test-audit` alone held 1,231,949,318 bytes; other large entries included CI,
release and diagnostic evidence. These artifacts remain untouched and do not
establish the production incident's cause.


## Producer-specific lifecycle (#212)

Admin > Maintenance > Media and cache adds **Cache inventory and retention**.
**Scan cache** builds a non-destructive resumable preview; the browser continues
bounded slices while the mounted surface remains visible. **Clean eligible cache**
approves that exact preview ID and continues deletion in place. A changed/stale
ID is refused. Hidden/unmounted pages stop browser continuation; explicit Continue
controls remain available. Transport failures stop writes rather than retrying.
The four-pixel bar is indeterminate while the total is unknown. Category totals,
up to sixteen candidate examples, actual reclaimed bytes, skipped entries and
partial/error counters distinguish measured work from protected storage. Copy log
copies the bounded recent diagnostic trace; the Admin log retains server events.
The original updater-trash-only button retains its narrower scope.

The two cache owners share a compact side-by-side strip on wide screens and
stack on narrower screens. Each exposes its actions and one status line;
operational timestamps, scope explanations and owner measurements remain in
native disclosures. Empty job indicators reserve no space. The layout preserves
delegated forms, progress regions and in-place diagnostic controls.

| Producer | Retention and deletion authority |
| --- | --- |
| Native completed `test-audit/<run>` reports | Eligible after 24 hours of inactivity with matching current schema/source/run identity or the verified historical profile/environment/task contract, plus the final matching Markdown footer. The inherited runner writes this Markdown last inside the run tree; later publication paths must be outside that tree. Owner-only, partial and unfinished runs remain protected. |
| Downloaded CI diagnostic directories | 24 hours of inactivity, only recognized export locations with a canonical completed `latest.json` audit record at the export root or one immediate artifact subdirectory. Parent/child plans never overlap. A directory name alone is insufficient. Published releases and unproven evidence remain protected. |
| Finalized Admin test reports | 24 hours of inactivity, matching producer kind and token hash. Unfinished recordings are preserved. |
| Registered disposable browser profiles and versioned release working copies | Fixed 24-hour lifetime; access/writes do not renew expiry. New browser fixtures record creation in `cache-owner.json`. Legacy roots get one immutable filesystem-derived anchor. Pins, incomplete/unsafe traversal and live ownership still prevent unsafe removal. |
| Managed physical and Smart Gallery ZIPs | Seven days, matching immutable metadata/path/size, with an exclusive existing artifact serving lease. Only the completed archive payload is detached; owner metadata and other ZIP formats retain their existing owners. |
| GitHub response JSON | 24 hours since the verified cached response timestamp, matching URL/key/response shape; writers hold a shared producer lease. |
| SimBrief preview drafts | Existing 30-minute expiry plus 60-second grace, matching draft shape. Oversized/unproven JSON is preserved by the bounded proof reader. |
| Download manifests and partials | Existing owners: physical manifests one day, smart manifests fifteen minutes, inactive managed build partials six hours. Existing build/reservation/serving policies remain authoritative. |
| Updater work | Contextual, **no TTL**. Retain the latest successful job's rollback (the previous installed version), active jobs, rollback source and selected beta recovery references. Only older verified completed/cancelled job payloads are eligible. Failed/running/unreadable state and legacy unversioned backups/extractions are preserved. |
| Upload resume state, originals, themes/branding, Gallery Trash, selected assets, unknown exports | Protected; no blanket age expiry. Derivatives use their existing source/version owners. |

The current installed application and the last successful job's rollback represent
current + previous versions; retaining recovery references can intentionally add
an exception. Legacy filenames/timestamps cannot establish this identity. Legacy
age-only backup and orphaned-running-job pruning has therefore been retired;
unknown legacy storage requires an owner migration or separate reviewed action.

Disposable root names are limited to the seven native browser fixture prefixes
(`map-browser-profile-`, `panel-lifecycle-browser-`, `breadcrumb-browser-profile-`,
`workflow-browser-profile-`, `widget-persistence-browser-profile-`,
`dev-dashboard-profile-`, `lightbox-race-profile-`) with their six-character random
suffix, and versioned `release-<version>` working copies with optional `source`,
`manual-build`, `pdf-preview`, `pdf-review` or `pdf-review-final` suffixes. Historical
compact versions such as `release-0111-source` are supported. Persistent
`release-preparation`, `release-qualification` and `publication-v_*` stay protected.
These explicitly registered disposable names do not require a completed audit
JSON report. Workspace age evidence is kept privately between scans and removed
after successful reclamation. Portable PHP has no reliable birth-time field:
for a legacy root the earliest available root mtime/ctime is frozen at first
observation; activity before that observation may have obscured its true creation
time. Subsequent activity never resets this fallback. A fresh scan is needed to
include newly registered names in an already reviewed preview.

An empty/finished **scheduled** lifecycle starts its own inventory through normal
maintenance or `--cache-only`, independently of thumbnail settings/time windows.
Cron can approve only its own policy-owned preview. A **manual** inventory/preview
waits for explicit administrator continuation/approval and is never auto-approved
by cron or Maintenance Center. After fifteen minutes without manual inventory
activity, cron replaces an unapproved plan with a new scheduled identity. The
replacement is visible; all old preview IDs are refused. Running manual cleanup
and detached deletion cursors are never replaced. Approved deletion debt can continue through cron.
Maintenance Center's existing `downloads.cache` task includes lifecycle work and
reports partial/refused owners; old analyzed plans require fresh analysis under
the changed registry revision. Cron JSON includes `cache_lifecycle.value`.
Eligibility after a TTL is not a promise of instantaneous removal: progress needs
repeated cron/browser slices, and active ownership/pins always take precedence.
The existing managed-download 4 GiB capacity/free-space policies remain in force;
this change does not impose a global cache quota or close the production incident.

### Bounds and recovery

The service lives in `app/services/cache_lifecycle.php` and its own part directory.
Shared path/checkpoint, producer-lease and passive-status primitives live in
`app/services/cache_lifecycle_storage.php`. They preserve the same API and guards
while keeping public cache writes and passive maintenance registration from
loading scan/deletion workers. Worker-lock/traversal primitives remain in the
module's own storage part. Explicit CLI/HTTP worker calls load the lifecycle
owner; passive display never advances a slice.
Inventory admits at most 5000 observations and 180 ms per slice; physical deletion
admits 180 observations/attempts and 180 ms. Blocking filesystem calls can overrun
admission. Each directory catalog retains at most 4096 names; a late deadline can
retry at most three bounded catalog attempts. Dense, persistently slow/unreadable
or deeper-than-64 directories are explicitly **partial/blocked**, preserved and
not included in measured byte totals. No successful partial scan is described as
an exact complete cache inventory. Ordinary runtime does not run the diagnostic
whole-tree CLI scan used during incident analysis.

Stable per-directory catalog byte offsets avoid repeatedly walking every prior
name. Candidate records are streamed into a private JSONL plan; the atomic state
stores its committed write offset, so a crash's uncommitted append is truncated
before continuation. A missing/truncated committed journal fails closed. State
and proof JSON reads are capped at one MiB; short checkpoint writes refuse replacement. Current inventory controls are excluded
from user-facing measurements, and superseded catalog journals are retired and
physically reclaimed in bounded slices. New producer coordination uses 256 stable
lock slots; collisions conservatively defer unrelated reclamation rather than
creating one permanent lock inode per historical report. Existing full-identity
leases from an earlier live checkout are still checked without unlinking them.

Reclamation rechecks guarded ancestors, root metadata/proof identity, current
recovery references and producer leases before atomically detaching a candidate
into private trash. `.keep` at a candidate root or cache ancestor pins it;
pins already present inside an inventoried subtree also protect that candidate.
GitHub writers share producer leases. Native audit completion is proven by the
JSON/Markdown pair without changing the trusted BASIC runner. Existing shared
leases still protect completed trees held by other consumers. Managed ZIP serving uses
its existing sibling `artifact.lock`. Updater pruning acquires start/worker locks;
Windows releases the child worker handle immediately before renaming a verified
terminal job, while the start lock continues excluding retry/rollback transitions.
A direct request for a terminal job only reads its payload. Failed/running work
is never admitted to this path. Do not edit immutable completed payloads concurrently
outside their owners while executing an approved preview.

Actual successful unlink bytes are persisted after each slice. A crash after an
unlink and before the counter commit can undercount reclaimed bytes; it cannot
recreate deleted files. Detached targets use deterministic identity so a crash
between rename and state commit remains resumable. Unknown content is never
reclaimed merely to make observed totals smaller. Detailed diagnostics include
stage, category counts, candidate bytes, partial/error markers, actual removals,
server insertion receipts and safe failure enums/code locations, without raw SQL,
credentials or exception messages.

`tests/cache_lifecycle_test.php` covers disposable preview/approval, stale IDs,
lease/pin protection, contextual current/older/failed jobs, journal-tail recovery
and actual deletion counts. The dashboard browser fixture covers delegated
in-place lifecycle continuation. Hosted BASIC proves source syntax and declaration
documentation only; runtime/filesystem/browser regressions need the central FULL
integration workflow. Local live verification can scan and inspect a preview
without clicking the payload-deletion control.

## Automatic visitor opportunities and optional scheduler monitoring

Installation and upgrade migration `202610140001_automatic_cache_cleanup.php`
seed the canonical `feature_flag.automatic_cache_cleanup.enabled` preference ON
only when absent. Admin Features owns the single switch; explicit later OFF is
preserved by migration replay and further updates. The existing Settings index
discovers it through the capability registry. OFF preserves payloads, checkpoints
and manual controls, and stops automatic lifecycle work including optional cron.
No thumbnail setting, daily window, cron setup or open Admin dashboard is needed.

Normal successful GET HTML visits to `home`, `gallery` and `smart_gallery` register
one shutdown opportunity. Media, HEAD, POST, error responses and explicit cache
transports do not start it. Workers load only after response rendering, through
the reviewed existing `site-maintenance-work` root. There is no visitor JavaScript
or browser-owned continuation. One suitable visit starts a token-authenticated
server chain, which continues across completed cycles without further visitors.
Work opportunities recur after thirty seconds. The registry resumes unfinished regional cursors; healthy completed regions become due hourly.
An overdue observation first serves a bounded existing cleanup slice, so sparse
arrivals cannot repeatedly scan/approve while starving the last candidate.
The next completed-cycle interval starts when deletion finishes; observation
age remains available separately through `last_observed_at`.
Unapproved manual plans retain their independent 900-second idle grace and get a
new scheduled identity, never automatic approval of the old plan.

| Visitor mode | Admission budget | Maximum slices |
| --- | --- | --- |
| Response finishing supported, measured pending <= 128 MiB (134217728 B) | 600 ms | 8 |
| Response finishing supported, measured pending > 128 MiB | 120 ms | 2 |
| Response finishing unavailable | 40 ms | 2 |
| Independent authenticated server-chain worker | 5 s | 32 |

`chain.php` serializes startup and timer requests with a separate kernel lock
and single-use private tickets. Timer hops wait at most 20 seconds, reduced by
the declared PHP execution limit while reserving eight seconds for bounded work,
dispatch and shutdown. Long gaps are split into further requests. The worker does
not extend the host's execution limit. Each admitted work attempt schedules its
next start 30 seconds later; timer-only requests do not claim inventory progress.
The existing token-protected endpoint accepts the private ticket only by POST.
Dispatch uses the configured `base_url`, never an incoming Host header, and sends
the maintenance token in a header. TLS verification remains enabled. A complete
socket write is only queued evidence; authenticated arrivals prove execution.

This generic PHP mechanism requires a configured absolute installation URL,
working same-installation HTTP connections, sufficient request runtime and an
available PHP worker. Waiting still occupies a worker. A hosting restart, killed
request, lost dispatch or unavailable transport can break the chain; a later
suitable visit recovers its expired lease without replacing cleanup cursors.
Optional external scheduling can supply recovery opportunities, but no provider
API, paid service or cron configuration is an installation prerequisite. Strict
uninterrupted timing across host outages requires an independent scheduler; a
PHP self-request chain cannot promise restart when every process is gone.

The default single-worker `php -S` development server cannot safely host a
waiting self-request chain. It reports `cache_chain_single_worker_runtime` and
retains short visitor opportunities. Non-Windows built-in servers with
`PHP_CLI_SERVER_WORKERS` of at least two can run the chain; Windows built-in
servers lack that multiprocessing support. This protects foreground HTTP
requests without changing the automatic capability preference or host timeouts.

Verified committed candidates are reclaimed during the same alternating bounded
worker burst, before the entire inventory finishes. Partial directory coverage
is a count of preserved paths, not a global worker failure. Genuine write,
ownership or capacity failures retain their safe stop/retry behavior.

Actual removals produce `cache.automatic_reclaimed` in the existing Admin log.
Each entry aggregates removed entries, bytes and the observation period; no
individual filenames, tokens or URLs are included. Confirmed summaries are at
least 300 seconds apart across scan cycles. Pending counters survive a failed
log receipt and can flush during a later idle burst; empty checks do not create
log entries. Logging failure does not undo or stop safe reclamation.
The log-attempt rate-limit claim is committed before calling the logger. Failed
receipts also wait for the next five-minute opportunity; a crash after insertion
but before its receipt checkpoint can repeat one aggregate, never per-file logs.

A newly discovered large queue lowers the current burst's deadline and slice cap.
A few-file approximately 100 MB cache can finish in one small burst. Many tiny
files still obey operation budgets and resume across visits. Each visitor slice
has at most 60 ms combined admission, 5000 inventory observations, a complete
catalog of at most 4096 names, and at most 180 deletion observations/attempts.
Catalog admission reserves its bounded operation allowance instead of labeling
an ordinary directory unsafe merely because the current slice ran out of work.
Retired journals get a separate <= 180-operation sub-slice inside that deadline.
The burst refuses further admission at eight MiB incremental allocation or with
less than eight MiB known PHP memory headroom; bounded state remains <= 1 MiB and
private metadata <= 64 MiB. Blocking OS/database/loader/checkpoint calls can exceed
admission deadlines: these are not hard response-latency or allocator guarantees.

Finishing the response still holds a PHP worker. A global 30-second visitor
throttle, nonblocking shared scheduler/site/lifecycle locks and durable checkpoints
limit occupancy. Foreground visits hold shared leases during rendering and never
wait for the cleanup lock. A burst refuses existing foreground activity and yields
between slices when a new arrival changes its bounded signal. This cannot observe
requests still queued in the hosting worker pool; short deadlines are essential
on a small pool. Without response finishing the short shutdown work may add to
the triggering client's latency. No execution-time extension is requested.
Unknown central maintenance ownership fails closed with safe diagnostic evidence.

Optional external cache-only invocations admit at most five seconds and 32 slices,
alternate inventory with already verified committed candidates and release the
lifecycle lock after each checkpoint. The same installation/scheduler locks prevent
concurrent workers. External cron is optional for idle-time opportunities, not an
installation prerequisite. Ordinary external cache-only requests remain bounded
and do not start legacy thumbnail chaining; only leased cache tickets continue
the independent automatic chain.

A region retains its observation round until its cursor finishes. A pending
deletion queue never restarts regional inventory from the root. Inventory and
deletion alternate within the existing committed checkpoint. Compact durable exact-path and ancestor coverage
markers fence queue membership against the committed plan offset, preventing
duplicates and parent/child overlap even when producer proof changes between
rounds. A crash before the state commit leaves markers beyond the write fence;
they cannot suppress resumed admission. Markers are private metadata within the
cycle's byte budget and are reclaimed with its journals. A replacement at an
already settled path is conservatively reconsidered in the next cycle, avoiding
duplicate accounting while an older queue still exists. Legacy checkpoints
without the queue index finish their existing queue before enabling repeated
rounds; they are never destructively migrated.

| Trigger | Idle-time behavior and resource cost |
| --- | --- |
| Real hosting CLI cron | Preferred where the account actually permits PHP CLI jobs; no HTTP worker is held. Shares filesystem/DB resources and the same owner locks. |
| External HTTPS scheduler | Works without visitors. Holds one PHP request worker during the bounded burst; provision its timeout above the admission budget and disable overlapping/retry storms. |
| Ordinary visitor opportunity | Default, independent of Admin/thumbnail scheduling. Starts authenticated server continuation; yields to foreground visits. A killed chain needs a later restart opportunity. |
| Windows Task Scheduler | Development installation only; works with the browser closed while Windows is awake and the task account can execute PHP/access the installation. |

This implementation targets generic shared hosting without required setup. For
optional progress during zero traffic, use a recurring HTTPS scheduler if CLI cron
is unavailable. Check the scheduler's actual
interval, request timeout and header/POST support at deployment. A URL-only
scheduler retains the existing legacy token interface, but credential transport
must be reviewed privately. No hosting-provider API or special PHP extension is
required. Never put a real token into repository examples or issue comments.

Where actual shell cron is supported, deploy this one-minute entry using the
installation's real PHP executable and absolute path:

```cron
* * * * * /usr/bin/php /absolute/installation/scripts/site_maintenance.php --cache-only --time-budget=5 --quiet
```

CLI needs installation permissions, not an HTTP token. Capture output through the
scheduler's rotating log/exit-code facility; do not append forever into cache.
Neither `--force` nor global maintenance enable/window bypass is required.
Cache-only lifecycle bursts preserve their own due gate even if an unrelated
caller supplied force for legacy updater-trash work.
An explicit direct lifecycle maintainer call may bypass regional due timestamps;
it still honors failed-region retry backoff, capability OFF and every deletion guard.
Passive evidence includes discovery rounds/cursors and indexed retry failures,
including a failed newer attempt alongside a retained completed measurement.
Legacy removal totals without a recorded removal timestamp retain an unknown
time; cycle completion is not substituted for confirmed removal time.

For external HTTPS calls, store this curl configuration outside the web root,
restrict it to the scheduler account, and inject the actual token privately:

```text
url = "https://example.invalid/index.php?page=site_maintenance_cron"
request = "POST"
header = "X-Site-Maintenance-Token: REPLACE_PRIVATELY"
data = "cache_only=1&time_budget=5&chain=0"
connect-timeout = 5
max-time = 25
fail-with-body
silent
show-error
```

Call `curl --config /private/gallery-maintenance.conf` every minute (or every
five minutes if that is the shortest supported interval). Do not follow redirects to another
host with credentials. The endpoint prefers the header, then POST token, then
legacy URL token; rotation invalidates old tokens. HTTP 403 means authentication
refusal, HTTP 500 or CLI exit 1 means failure; busy/not-due HTTP 200 or exit 0 is
an intentional yield, not progress. Inspect nested `cache_lifecycle.value` and
`cache_gc.value`; top-level cache-only `done` requires both owners to finish and
`skipped` requires both to yield. A partial inventory preserves inaccessible
data and is never evidence of complete filesystem coverage.

For Windows, create a task with the verified PHP executable as Program, arguments
`D:\laragonwebserver\www\Galerie\scripts\site_maintenance.php --cache-only --time-budget=5`,
and Start in `D:\laragonwebserver\www\Galerie`. Repeat every minute indefinitely,
run without an interactive login if the account supports it, do not start a new
instance while one runs, and set a one-minute execution timeout. Review task
history/exit codes and the application's evidence. These are instructions only:
this change does not register any Windows or hosting task.

After installation/update, inspect real arrivals and counter/phase changes across
ordinary visits. For optional external scheduling, verify actual deployment paths,
PHP limits, permissions and safe token storage, then inspect at least three arrivals
with the browser closed. `scheduler_health` distinguishes
unobserved, single arrival, observed successive arrivals and a gap exceeding
90 seconds. Arrival does not imply successful work. `last_attempt_at`,
`last_progress_at`, `last_observed_at`, `next_due`, `stale`, `stalled` and safe
reasons show blocked/stopped operation. A stale `running` marker is recovery
evidence on the next admitted burst, not proof of a live process. Cycle IDs
remain stable across slices; scheduler diagnostics overwrite one bounded private
control record, while existing Admin/PHP log owners retain their own rotation.
`chain_health`, `chain_arrival_at` and `chain_next_start_at` disclose safe server
continuation evidence; they never expose the ticket or maintenance credential.

`candidate_bytes`, category totals and sample entries are historical measured
observations over the scan window. `pending_bytes`/`pending_candidates` exclude
settled candidates; actual successful unlink bytes are `bytes_removed`. A
revalidation refusal settles the old plan entry, leaves the source protected and
requires a later observation before reconsideration. Do not subtract reclaimed
bytes from historical totals and pretend they are an instantaneous cache size.
A crash after unlink but before its counter commit can undercount actual bytes;
the deletion remains safe and resumable. Old schema-1 checkpoints lack settled
per-candidate counters; completed states report zero backlog, and fresh cycles
provide the complete new metrics without losing old deletion cursors.

### Capacity and throughput

At one minute/five seconds, the hard slice-count ceiling is 32 per invocation,
not a promise of that many admissions. Deletion admits 180 observations per slice,
including directory traversal and failed attempts, rather than 180 payload files.
Measure counter deltas and elapsed time on the target filesystem before choosing
a budget. Estimate drain time from remaining file/directory counts divided by
measured net removal rate, then account for concurrent arrivals, leases, pins and
blocked owners. Nine hundred GB alone says nothing about the number of operations
or the time to drain it. Five-minute invocations provide roughly one fifth the
minute-based opportunities; hourly invocations offer fewer backlog opportunities than visitor traffic or minute-based scheduling.

Private metadata is capped at 64 MiB per cycle, plus bounded atomic-control
temporary files and one bounded catalog temporary. Admission requires 16 MiB of
reported free space when the host exposes a valid measurement; known-low capacity
blocks new scheduled work. WEDOS and other shared hosts may disable
`disk_free_space()`. Missing, failed or invalid probes remain explicitly unknown
and permit the already checkpoint-validated worker. The scheduler record commits
before admission and each detached candidate cursor commits before unlink.
Failed journal/checkpoint writes stop work under the same metadata bounds.
Unknown space is neither a measured reserve nor evidence of an account quota.
The private scheduler record retains `free_space_known`, nullable `free_bytes`
and an exception class without messages or credentials. Payload
detachment is a same-filesystem rename, not a second 900-GB copy. Retired journals
are reclaimed in short slices alongside ongoing work; a new completed cycle
waits for old journal debt to drain, preventing indefinite accumulation. Keep at
least 80 MiB available for metadata/control headroom, plus normal producer writes
and filesystem inode headroom. This is a planning reserve, not a disk quota.
Directories above 4096 names, depth 64, unreadable paths or metadata budget
exhaustion are explicitly partial and preserved; inspect these boundaries before
claiming complete coverage of a large production installation. With insufficient
space, free separately approved storage first; the worker never deletes unproven
data to obtain checkpoint room.

`tests/cache_lifecycle_scheduler_test.php` adds browser-free multiple-burst,
early-reclamation/fairness, refresh throttling, manual supersession, concurrent
admission, scheduler outage, corrupt-state refusal and detached-candidate recovery
fixtures. Existing retention fixtures cover TTL, leases, pins, changed proof and
ZIP/recovery rules. Hosted BASIC does not execute these fixtures; runtime proof
requires exact-candidate hosted FULL. Target filesystem throughput, scheduler
configuration and production before/after sizes remain deployment checks.

Workspace age records now bind their fixed anchor to the current producer marker and filesystem device/inode identity. An unbound historical age record is re-anchored conservatively, and changed identity is revalidated under the exclusive deletion lease before detachment. This protects a replacement at the same path from inheriting expiry. Producer-free legacy workspace usage is unknown; retain the conservative path while supported legacy roots remain.
