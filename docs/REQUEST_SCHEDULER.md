# Visitor scheduling and Cron Overview

Ordinary eligible PHP HTML GET/HEAD visits, including crawler visits, provide
scheduling opportunities. No hosting cron, Admin visit, browser timer, daemon
or external queue is required. Static/media delivery, private workers,
explicit maintenance transports, previews and AJAX/status reads stay passive.
A site without PHP requests cannot wake itself: **next eligible** is a due
threshold, never an exact wall-clock execution promise.

## Task inventory and authoritative owners

The initial hooks were independent updater, log-archive and site-maintenance
registrations in `app/bootstrap/maintenance.php`. The latter also registered
attachment counts, telemetry and cache opportunities. Archive and thumbnail
fallbacks were restricted to Admin visits. The central seam now admits those
owners from visitor opportunities, with one background task per tick.

| ID | Owner and persisted evidence | Effective timing and safeguards |
| --- | --- | --- |
| `updates` | `updates_request.php`, `updates_job_lookup.php`, updater app settings and `cache/updates` job/chain receipts | Configured 5/15/30/60-minute discovery; active-job continuation has its own chain lease. Installer capability, automatic preference, beta dry-run policy, discovery/start locks and GitHub backoff remain authoritative. |
| `logs` | `admin_log_archives.php`, retention setting and `cache/admin-log-archive-maintenance-state.json` | `next_run_at` includes the 24-hour cadence, one-minute backlog and one-hour failure retry. Forever disables automatic archival. One verified historical day per bounded owner invocation; only verified represented live rows may be deleted. |
| `site` | `site_maintenance.php`, daily UTC window, app-setting checkpoint, completion date and request-attempt touch | Enablement, request-trigger preference, configured daily window and request interval remain effective. A scheduler-only slice excludes unrelated cache/telemetry work and does not launch a serial run of every owner. |
| `cache` | Cache lifecycle visitor/scheduler owner and existing scheduler/chain receipts | Existing 30-second refresh, foreground priority, capacity and checkpoint policy, mutation/site locks and bounded worker slices. Existing cache-chain resumption remains supported. |
| `update_cache` | `updates_jobs/cleanup.php`, `cache/updates/cache-gc.json` | Separately eligible detached updater trash, with persisted retry/idle deadline; no automatic admission when detached trash is absent. Updater start lock, safe relative paths, active-pointer refusal, deletion budget and rollback protection remain in its owner. |
| `telemetry` | `telemetry_rollup.php`, telemetry settings and latest `telemetry_job_runs` row | Independent installed interval/retry policy, capability/collection preference, schema policy, DB-scoped nonblocking lock and bounded retention/rollup. Status reports actual job evidence. |
| `attachments` | `gallery_attachment_usage.php`, `gallery_attachment_usage.background_state` | One-shot initial reconciliation, not an invented periodic inventory. Completion is terminal; tickets, owner exclusion and failure retry preserve resumption. No files are scanned to render status. |

The integrated scheduled-publication owner from #284 remains a distinct tiny
foreground phase inside the same lifecycle entry, before discovery/rendering.
Its indexed gallery deadlines, schema policy and canonical writer lease remain
authoritative. It is not forced early by a background-task Play action and is
not presented as a recurring cron task. Future one-shot foreground adapters
belong at this same seam rather than adding another bootstrap hook.

## Admission and recovery

`request_scheduler_register_visit()` is the single bootstrap entry. It applies
eligible foreground publication, then registers one successful-response
opportunity from the small `request_scheduler_trigger.php` owner. Only an
admitted shutdown callback loads the planner and task observations; excluded
routes and denied responses do not load that closure. The cache owner retains
its shared foreground-observation lease
and arrival signal through page rendering; that observation cannot execute a
cache job. `request_scheduler_tick()` is shared by visitors and optional
hosting calls. It observes due state, chooses the least recently admitted
eligible task with stable ID ties, and admits at most one bounded owner.
When the existing FastCGI/LiteSpeed primitive finishes the response, the same
request consumes its ticket and executes that owner directly under the worker
flock. This path needs no loopback socket. Other runtimes use the existing
private-worker transport; a failed handoff remains retryable and never delays
rendering with an inline heavy fallback.

A nonblocking installation flock and a single 180-second handoff lease live in
`cache/request-scheduler/`. Tickets are random 256-bit credentials and consumed
before work while the flock remains held. When needed, the private transport
extends the
existing token-authenticated `site_maintenance_cron` POST with an exclusive
`scheduler_ticket` mode. Mixed modes, malformed or replayed/expired tickets
cannot execute work. The worker performs one selected bounded owner call; it
does not call the scheduler again or form a scheduler HTTP loop.

Owner enablement and the Maintenance Center claim are rechecked before work;
non-forced work rechecks its current owner deadline. Existing per-owner locks,
limits and schema checks remain mandatory. A lost request expires; a live
worker retains its flock even beyond the lease. Failed handoffs back off for
30 seconds without changing domain schedules. Dispatch receipts describe
attempts/admission, never fabricated task completion. Queued handoffs remain
visibly queued until the worker consumes their ticket; only then do they show
running.

## Admin operation

Open **Maintenance → Cron Overview**. One-line desktop rows target 30–36 px;
mobile controls retain 44 px touch targets and the table scrolls horizontally.
Play appears immediately before each task name; there is no action column.
Task names link to the existing owner settings/controls: Updates, log
maintenance/archives, dashboard Media and cache, Telemetry or Maintenance Center
for the one-shot attachment reconciliation. No second settings store is added.
Controls use the shared 16 px outline icons and compact rounded surfaces.
Sort Task, Cadence, Next eligible, Last run or State. Sort keys use normalized
values, unknown times sort after known times, and stable name/ID ties avoid
jitter. Details remain in compact contextual text/tooltips.
Known future thresholds retain their full absolute time and add a localized
parenthesized duration with up to two components. This is display information;
sorting and admission continue using the original epoch.
An intentionally absent next deadline is shown as Disabled, Completed or
Nothing pending cleanup (no updater trash). Other unscheduled rows say Not
scheduled. Missing last-attempt evidence says No recorded run. Unknown remains
reserved for unavailable evidence, with a safe explanation in the state detail;
the UI never fabricates a future deadline or a completed run.

The visible mounted page polls `admin_cron_status` about every 1000 ms, with
one read in flight, no-store authenticated JSON and bounded error backoff.
Immediate resume/reconciliation replaces any pending sequential timer, and
only a settled read may schedule its successor.
Hidden/unmounted views stop observing. Updates patch existing cells and retain
sort, nodes, focus and scroll. The read endpoint never calls the scheduler,
creates coordination state, repairs updater pointers, scans inventories or
checks remote releases. Unavailable evidence stays unknown.
These JSON polls do not render another page or emit a public viewer/page-view
event. An ordinary initial document visit remains a scheduling opportunity;
passive polls do not create further visitor opportunities. HTTP access logs
still record the JSON requests.
The icon beside the heading pauses/resumes only this view's live preview.
Pausing cancels its read/timer and invalidates late bodies; it survives a hidden
tab round trip. Resume observes immediately. It never disables visitor work or
manual actions; an explicit action still updates its affected row while paused.

Play sends one CSRF-protected `admin_cron_run` POST for an allowlisted task.
It overrides only timing, rechecks owner safety and returns honest
started/continued/running/busy/skipped/completed/failed feedback. It refreshes
the affected row immediately and reconciles persisted state after uncertain
responses. Native POST/redirect is the no-JavaScript fallback.

## Optional hosting URL

The collapsed hosting section explicitly creates/regenerates or revokes a
narrowly scoped 256-bit hosting token. It is independent of private worker
tickets and the existing maintenance secret. Revocation does not change any
visitor preference. Missing/invalid tokens do not create a secret on a public
request. The generated URL uses `url_for` and the configured installation base,
including subdirectories and non-rewrite query routing. For example, the UI
provides `curl -fsS '<generated secure URL>' >/dev/null`; invoking it every
1–5 minutes is optional and all task windows/throttles still apply.

Hosting responses are no-cache safe status only, with no private task data,
paths or credentials. Protect the copied URL like any installation secret.
Only exact 64-hex tokens are accepted with a constant-time comparison. A valid
URL reserves at most one call per 60 seconds in shared flock-protected state
before domain observations or handoff. Repeated/concurrent calls return safe
`throttled` status and `Retry-After`, without executing a task or changing its
cadence. This bound is independent of visitor/manual admission and survives
token rotation. Invalid/revoked tokens never create scheduler state. The URL
protects the application trigger; ICMP/network floods require hosting-level
network controls and cannot be prevented by a PHP URL.
Visitor scheduling remains active when this URL is absent, revoked, unused or
called concurrently.

## Runtime and qualification

Authored runtime roots reuse the small `site-request-trigger`,
the deferred planner/observation root `archive-request-trigger`,
`domain-admin-maintenance-center` for Admin layout and
`domain-site-maintenance` for the private worker/hosting route;
the existing `request-maintenance` updater closure remains unchanged.
No logical module or baseline is added. Worker call edges are deferred until
the existing owner is explicitly loaded. The deferred root explicitly owns
the deferred scheduler entrypoints and their passive observation closure,
the lightweight Maintenance Center claim observer and latest telemetry run
reader; passive admission does not depend on an incidental heavy-worker load.
The source adds six PHP files
(controller, view, small trigger, planner and two owned planner parts); route/file
closure deltas remain subject to hosted preparation and unchanged ratchets.
The reviewed baseline allows 98 logical modules and 3653 duplicate direct file
entries. Do not ship source-only hooks with a stale generated plan.
Local include-regression recovery generated 98 logical modules and 3652
duplicate direct file entries, within those unchanged limits; hosted preparation still owns
the complete final inventory/manifest and runtime qualification.

Hosted source BASIC checks syntax/declaration contracts only. Runtime,
database, concurrency and browser fixtures belong to authorized hosted FULL
on the exact prepared SHA. Real WEDOS/shared-hosting and non-rewrite live smoke
remain separate installation acceptance; this document does not claim them.
