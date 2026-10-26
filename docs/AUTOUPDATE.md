# Visitor-started automatic updates

Enable automatic stable updates and select five minutes in Admin > Updates.
The default is five minutes; explicitly saved 15/30/60-minute choices remain
supported. The first eligible GET/HEAD at or after that interval checks for a
new stable version. A normal gallery visit is sufficient. Login and browser
JavaScript are unnecessary. Admitted robots count; the SEO guard rejects blocked
requests before the update trigger. Five minutes is the discovery interval,
not a guarantee that downloading and installing every package finishes by then.
GitHub rate-limit waiting remains mandatory.

Discovery creates the durable background job. After a successful visitor
response, the server sends one private continuation to the installation's
configured `base_url`. Each independent worker advances a normal three-second
slice and sends the next continuation. This continues through the existing
download, validation, staging, backup, activation, migration and cleanup engine
until the job completes. The user may close the page immediately. No hosting
cron needs to be configured. The server does not keep an idle worker waiting
for the next five-minute discovery boundary: discovery of a future release
still requires another eligible visit.

The installation origin must be reachable from its own server. HTTPS uses
normal certificate verification. The transport never uses the incoming Host
header, never follows an arbitrary URL, and waits at most one second for
connection/write admission, without waiting for the child response. A host must
have enough PHP capacity to serve independent requests. Single-worker built-in
development servers retain the ordinary visitor-driven fallback.

Each handoff needs the existing private maintenance token and a random,
single-use continuation ticket. Only the ticket's SHA-256 hash is stored.
The controller requires an exact POST envelope and rejects mixed worker modes,
query-string tickets and malformed tickets before worker execution. Both the
installation chain and the individual updater worker use nonblocking locks.
The private endpoint performs no implicit maintenance before authentication.

The `updates_background.php` service belongs to the deferred `updater-work`
module. Loading the ordinary updater job entry must not load this service.
The updater-only transport loads its existing single-worker admission policy
explicitly before calling it; routine telemetry transport keeps that policy's
storage/model closure deferred. Generated runtime plans and real route include
ratchets verify these ownership boundaries without increasing their budgets.

`cache/updates/background-chain.json` retains the job identity, ticket hash,
lease deadline, last dispatch, actual last arrival and a redacted failure code.
Its contents are private installation state. A complete socket write proves
dispatch only; `last_arrival_at` proves an authenticated worker consumed its
ticket. A live queued/executing lease keeps ordinary visitors out of heavy
updater work. Lost arrivals become recoverable after 120 seconds. Failed
dispatch admission is retried no faster than thirty seconds; ordinary visits
still retain the previous bounded worker fallback.

Failed worker stages stop chaining. A killed slice leaves a durable open-slice
receipt; the next exclusive worker converts it to a caught transient failure.
An abandoned manual slice is inspected after its ten-minute recovery deadline;
an ordinary paused manual job remains owned by its Admin/API caller.

## Recovery admission

Recovery completes an already requested operation regardless of automatic,
Admin/API or Advanced commit-ID origin. It does not enable automatic discovery
or change the saved channel. Capability OFF stops all installer work. Running
manual jobs retain their owner; failed manual jobs participate in safety repair.
Automatic installation of a future release still requires autoupdate ON and the
stable channel. Beta availability remains metadata-only.
An admitted automatic retry durably marks repair continuation so further slices
can finish that original manual job without changing its trigger. An ordinary
paused manual job without a prior failed-repair admission is never taken over.

Transient failures admit three automatic retries after 60, 120 and 240 seconds.
The total budget survives successful intermediate slices and explicit retries.
Undefined functions, deterministic integrity failures and legacy reference
`F05D71A153A2` never repeat the same defective package automatically. New jobs
resolve the trusted source to a full immutable GitHub commit; retries keep it.
A legacy moving-branch download without that identity stays stopped.

At least 600 seconds must pass from the first caught failure before abandoning
the original attempt. Time passing is eligibility, not a timer process: a later
worker opportunity must execute the action. The global start lock and original
worker lock serialize the decision.

| Proven boundary | Automatic action after retries/timeout |
| --- | --- |
| Before activation, with no conflicting activation marker | Release the active pointer; application files were untouched. Retain the failed job and artifacts. |
| Partial or completed activation, no migration effects, isolated runtime and complete verified backup | Create one independently identified rollback job restoring the actual previous application snapshot. |
| Entered/unverified migration, changed/unknown DB, normal runtime use, unsupported isolation or missing/corrupt backup | Keep the failed state, show the reason and maintenance repair steps; continue due metadata discovery where reachable. |

The rollback has its own workspace, checkpoint history and backup of the failed
tree. A source reservation is durable before child allocation; repeated recovery
or interrupted pointer publication reuses that identity. Source and rollback are
linked, the original remains failed, and superseded sources cannot be restarted.
The rollback restores overwritten and removed managed files, removes paths the
failed update created and restores the saved stable/beta settings. It never
touches media/configuration or reverses database migrations. A failed rollback
has its own finite transient budget and cannot create another rollback.

### Actual database safety

Before first activation, an exclusive `runtime-use.lock` waits for existing
ordinary request/CLI shared leases to drain. Busy ownership yields without
consuming a failure retry or spinning a continuation chain. A durable
`rollback-window.json` is opened before file changes. Packages declaring
isolation protocol 1 and entering it from bootstrap preserve the activation gate
through migrations/finalization/cleanup, until job completion. Normal HTTP/CLI
work receives a private 503 while gated. Authenticated Admin update requests and
a correctly hashed, unexpired, job-bound private updater ticket may bootstrap;
normal controller authentication, CSRF and maintenance-token checks still apply.

Admission of ordinary runtime during an open window leaves a permanent
`runtime-used.json` witness before application/domain work. This invalidates
automatic rollback even when schema and ledger still match. Missing or closed
windows and older target packages without the cooperative protocol also refuse
post-activation rollback. This contract coordinates Gallery processes; arbitrary
external database writers or manual deployments must be stopped by the operator.

After acquiring exclusive ownership, the updater records fresh bounded actual
column/index definitions and the complete migration ledger. Before restoration
it checks them again, with schema caches invalidated. Applied migration file
hashes are retained and checked against the restored snapshot, or unchanged
installed bytes when no replacement was needed. Migration intent is saved
before invoking the real runner. Safety requires either explicit not-started
intent or a completed runner receipt listing zero executed migrations. An
interrupted migration can already have committed DDL without a ledger row; it
remains unverified. No generic post-migration compatibility claim is made.

### Integrity, discovery and operations

Every new package job requires complete normalized SHA-256 core-manifest coverage
according to the canonical integrity policy. Other updater-owned package assets
(such as vendor licenses or binary resources) retain trusted-origin/archive
validation and receive private raw SHA-256 identities before staging. Snapshot
hashes also preserve exact original bytes. Normalization cannot hide a change
to a binary resource or rollback snapshot. All jobs retain
matching version/algorithm and the existing origin/path/permission/size checks.
Only already persisted legacy jobs retain their prior compatibility reader.
Package and ready/backup checks hash at most forty files per bounded slice,
persist cursors and recheck prepared bytes at activation. Missing/changed backup
metadata or bytes stop before active file mutation.

After release or successful rollback, discovery may install a strictly newer
stable version. It excludes the highest failed stable version and up to 32
recent failed full commits, without deleting any failure evidence. An explicit
manual selection remains possible under its usual validation. A failed job that
cannot be safely repaired still permits independently throttled metadata checks;
it is displayed as failed with guidance, never as ordinary pending/continuing.

No idle worker waits through backoff. Eligible visits and existing cron provide
later opportunities. Once a failure leaves the activation gate closed, ordinary
visitors receive 503 before the scheduler; autonomous recovery then needs the
hosting CLI runner `php scripts/application_update.php` or an authenticated
Admin update request. A failed private chain does not schedule a future timer.
The full authenticated Updates page supplies one due updater-only opportunity
after its output. It does not publish galleries, run unrelated maintenance or
override deadlines; AJAX/status reads remain passive.
Configure hosting cron if unattended recovery must continue during a gated
outage or without visits. A single-worker/loopback-restricted host retains this
bounded CLI/Admin route. Writable protected cache and working cooperative
`flock` are required; an unavailable lease never permits unverified activation.

## Already stuck installations

Publishing a release cannot install this mechanism into an older stuck updater.
An old job also lacks the new proof. In particular a migrated `0.144.1` failure
with reference `F05D71A153A2` is **not automatically repaired**, including by
copying only new updater files. Do not edit a job's target/package or infer
database compatibility from a successful file restore.

An administrator can perform a coordinated manual forward repair:

1. Stop visitor, cron and private workers; enable hosting maintenance mode.
   Preserve a consistent database/files backup and all updater job, archive,
   activation-marker and rollback evidence.
2. Inspect actual applied and partially executed migration effects against the
   selected release. An unrecorded migration can already have committed DDL.
   Establish compatibility or obtain a migration-specific repair; do not reverse
   migrations or restore application files alone.
3. Verify and deploy the **complete compatible fixed release** using supported
   manual deployment, preserving configuration, media and updater evidence.
   Run forward migrations and verify schema and application in maintenance mode.
4. Only after verified repair, with all workers stopped, archive the matching old
   `active-job.json` pointer beside its failed job. Keep the job and package.
   Never discard a live activation gate without reconciling its exact owner and
   the complete installed tree. Restore scheduling and perform fresh discovery.

If compatibility cannot be established, retain maintenance mode and obtain
specialist repair or coordinated database/files restoration. This is a manual
administrator operation. Real hosting, external DDL, deployment permissions and
proxy/PHP termination remain installation acceptance work; automated tests use
only owned disposable resources.

The optional `scripts/application_update.php` runner remains supported for
installations that already use external scheduling. It is not required for the
visitor-started chain. Normal installations receive recovery through a complete
release; older stuck installations need the assessment above.
