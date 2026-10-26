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
| Old `activate` checkpoint, no activation intent/gate or migration intent, complete original-byte proof under start/worker/exclusive runtime locks | Release only the active pointer after every planned original and backup matches and every originally absent path remains absent. Retain all artifacts. Stage name alone proves neither partial activation nor safety. |
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

Before first ordinary activation, the prepared job publishes its activation gate
before trying the exclusive `runtime-use.lock`. New ordinary requests receive
503 before taking a shared lease, while existing readers finish. This ordering
prevents continuing visitor traffic from starving activation. It also applies
when an older persisted job next reaches activation. Worker-lock contention yields
without consuming a failure retry. A durable
`rollback-window.json` is opened before file changes. Packages declaring
isolation protocol 1 and entering it from bootstrap preserve the activation gate
through migrations/finalization/cleanup, until job completion. Normal HTTP/CLI
work receives a private 503 while gated. Authenticated Admin update requests and
a correctly hashed, unexpired, job-bound private updater ticket may bootstrap;
normal controller authentication, CSRF and maintenance-token checks still apply.

Ordinary browser GET requests accepting HTML display a compact "We'll be right
back" maintenance page with inline light/dark styling and a Try again link.
The current GET URL refreshes after three seconds, including without JavaScript.
HTTP 503, private no-store and retry advice remain; robots receive noindex advice.
HEAD has no body. POST, JSON and media clients retain the plain unavailable
reply without a refresh, so maintenance cannot automatically replay form data.
The early page intentionally uses English fallback text and browser color
preferences: configured Theme, translations, database and mutable assets cannot
be loaded at this isolation boundary. The response does not open admission or
change updater ownership.

A progressed worker waiting for runtime readers behind its own closed admission
gate retains a private successor. The handoff pauses for one second after
releasing both worker and chain locks; public visits are not needed to restart
it. An occupied worker that made no progress still stops the chain. Discovery
retains the configured Admin session name after `session_write_close()`, keeping
authenticated status/recovery reachable during post-response automatic updates.

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

Database evidence format 2 includes the ordered complete ledger (versions and
applied timestamps), actual schema fingerprint, applied-file hashes and the
installed manifest identity. A historical applied version absent from that
manifest is recorded explicitly with a null file hash. It stays in the ledger
and never becomes pending again. A file declared by the installed manifest
must exist with its declared bytes; unknown inventory, unsafe paths or corrupt
bytes refuse activation. The incoming package cannot prove historical absence
in the installed release. Unversioned saved witnesses retain their original
versions/schema/all-file-hashes checks; they never acquire invented timestamps
or null absence evidence. Unknown witness formats fail closed.

The initial authenticated Admin start request may have bootstrapped before an
active job existed. After Admin, CSRF and installer-capability checks, its
controller releases only its own shared runtime lease before starting work.
Later Admin/AJAX requests use the same boundary. The post-response scheduler
releases its request lease after domain handling; private workers require their
job-bound ticket and maintenance token; the dedicated CLI declares its trusted
worker bootstrap. Other HTTP/CLI leases continue to block activation. A saved
`runtime_lease_busy` flag alone is not proof of a deadlock or live lock owner.

Integrity progress uses the durable verified-file cursor and file count for
each package slice. Entering a new checkpoint resets the previous counters and
message, so extraction's 100% is never displayed as verification progress.
The normal interaction stays Update followed by automatic continuation. Details
label the lifetime `attempts` value as **Processing runs** and explain that it
counts short worker requests, including waits, rather than errors or Retry
clicks. Retry continues the same job and retains that count. Runtime contention
shows explicit waiting and polls every two seconds instead of every 250 ms.
Unknown local files outside updater ownership are preserved and do not become
installation blockers merely because they are PHP files.

`update.worker_failed` is emitted by the worker, including non-Admin paths. It
records job/stage/reference, exception class, owner basename/line, processing
and automatic-retry counts and activation/backup/migration receipts. Migration
evidence failures add a validated version identity and missing/changed reason.
The same bounded receipt stays privately in `job.json`, outside public result
data. Confirmed Admin log insertion is checked; unavailable logging falls back
to PHP's error log without blocking checkpoint persistence. Raw exception text,
paths, SQL, credentials and tokens are excluded. Supported historical missing
migrations produce a nonblocking notice with their ledger identities.

### Integrity, discovery and operations

`update.autoupdate_started` reports the target version and says installation is
still running; its context includes installed/target versions and branch.
`update.autoupdate_completed` is emitted only after a background stable job is
durably completed, with previous/installed/target versions and copied-file count.
Reading an already completed job does not emit completion again. Log insertion
failure cannot convert a completed update into failure.

`APPLICATION_UPDATE_PACKAGE_INTEGRITY_ENABLED` defaults to `false` until an
explicit future change enables it. The publisher verifier remains implemented,
but package and ready stages do not run its file sweeps, including FTP diagnostics.
The Admin calls this short stage **Preparing package**. Installation uses the
selected trusted archive bytes and pins exact copy hashes during staging and
activation. Required runtime files, archive ownership/paths, transport limits,
backup/DB recovery evidence and removal policy remain enforced. Release-time
manifest generation, package-build checks and the separate Integrity tool remain
available. Re-enabling the flag restores the retained publisher verifier for
newly validated jobs; it does not reinterpret already staged checkpoints.
Only already persisted legacy jobs retain their prior compatibility reader.
Staging and backup work remains bounded and activation rechecks prepared bytes.
Missing/changed backup
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

### Overwrite-only FTP completion

Upload the complete release ZIP over the installed application. Preserve local
configuration, media and private data; no preparatory deletion is required.
Choose **Reset updater / finish FTP update** on the job card or in Advanced
tools. It starts a fresh `manual_deploy` job, stops the old active and gate-owner
jobs under their worker locks, preserves their records/backups and the exact old
marker bytes, then closes admission before waiting for existing ordinary readers
to drain. Only activation/migrations take the exclusive runtime lease; continued
visitor traffic cannot starve reset before its new job is created.
An in-flight rename or migration finishes before takeover. The browser shows
why replacement is waiting and repeats the replacement action, never the old
worker. Reset never deletes the job history or reverses database migrations.

**Install stable** is always available in Advanced tools, including when the
installed version is current or a job is stopped, paused or running. It performs
the same ownership replacement, downloads the configured stable branch head at
one pinned commit and overwrites every packaged application file. No FTP step,
newer-version check, comparison against the damaged installation, historical DB
rollback witness or old job checkpoint is required. It preserves configuration,
media, unlisted local files and backups; obsolete-file pruning is skipped.

When the optional verifier is enabled, explicit Admin manifest checks are advisory. A missing,
malformed, stale or version-mismatched manifest is logged as
`update.recovery_validation_warning`; recovery continues. Content differences
are warning receipts with repository-relative file identity and expected/actual digests,
shown in the job and logged as `update.file_integrity_warning`. The actual
selected bytes remain pinned through staging and activation. With the flag
disabled, both the ordinary sweep and these diagnostics are skipped. Trusted HTTPS download,
safe ZIP paths, bounded extraction, required application files, runtime ownership
and actual migration execution remain enforced. Publisher metadata is not a
prerequisite: an optional inventory selects existing safe files, with safe source
enumeration when it is unavailable. Staging pins the actual selected bytes,
including the manifest itself, independently of diagnostic results.
A failed FTP retry restarts installed-file verification, never archive download.
A new reset supersedes even an interrupted recovery. Old DB effects are retained;
there is no automatic rollback from an explicit recovery with no old DB witness.
Interrupted takeover reuses its journaled zero-slice child, including a partially
transferred gate, without cancelling that child or overwriting original receipts.

Newer manually installed code prevents old downloaded jobs and their automatic
rollback from replacing it. The installed version is read from disk rather than
an already-loaded old bootstrap constant. New jobs also retain their starting
installed version; legacy jobs retain validated target evidence. Old source
identities and backups remain available for diagnosis after manual completion.

**Pause** requests the next safe checkpoint in every stage, including activation
and migrations. It never kills PHP inside a file rename or SQL statement. A
partial activation remains gated while paused. **Continue update** resumes the
same ID; it does not clear rollback or migration evidence. An in-flight browser
continuation cannot replace a newer explicit paused state. Advanced commit-ID
installation accepts older supported release commits as well as newer commits;
it pins the chosen code and never reverses DB migrations. Older code may require
a compatible coordinated database/files backup.

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
   Use **Finish FTP update** to verify the uploaded tree and complete forward
   migrations, preserving local extras and the old recovery records.
4. Verify schema/application and completed channel metadata in maintenance mode.
   Matching old activation ownership is reconciled by that completion job;
   never manually discard a live gate. Restore scheduling and fresh discovery.

If compatibility cannot be established, retain maintenance mode and obtain
specialist repair or coordinated database/files restoration. This is a manual
administrator operation. Real hosting, external DDL, deployment permissions and
proxy/PHP termination remain installation acceptance work; automated tests use
only owned disposable resources.

The optional `scripts/application_update.php` runner remains supported for
installations that already use external scheduling. It is not required for the
visitor-started chain. Normal installations receive recovery through a complete
release; older stuck installations need the assessment above.

### Recovery of the reported c2e2bc77 installation

The operator reports installed beta commit
`c2e2bc776b727ef95ae2a2a20c4acc4c6da62513` (version 0.144.2), intending
to move to the separately prepared 0.144.4 release. Its updater contains the
same unconditional applied-migration-file hash requirement. The supplied
historical `20261026045933-1aae433301a9` snapshot targets 0.144.3 at
`9ffb0ed97163c120f7ab699922ea1c920d3fdb70`. Reference `F8D315905480` matches
the missing-target path exception. The snapshot has complete backup/ready
verification and explicit `migrations_started=false`, but no database baseline
or open runtime window. Its five planned files are `ARCHITECTURE.md`,
`PATCH_NOTES.md`, `README.md`, `app/core-manifest.json` and `app/bootstrap.php`.
The exact missing path and present production state are unknown. The removed
experimental migration files are a supported plausible cause, not an observed
production ledger. Attempts count worker requests, not failures.

Before any deployment, the administrator should:

1. Put the host in maintenance mode outside Gallery, stop cron/private workers
   and wait for active PHP requests to drain. Preserve a consistent DB/files
   backup, installed manifest, the full job directory (including extracted and
   ready files and original backup), active/last pointers, activation/window
   markers and chain diagnostics. Preserve lock files; stale diagnostic lease
   JSON is never a reason to delete a kernel lock.
2. Read the current job, ledger and actual schema. Compare the installed
   manifest's declared migrations with disk and the complete ledger. Obtain the
   private server log for the reference if available; do not delete historical
   rows. Under start, job-worker and exclusive runtime ownership, check for a
   gate, activation intent/completion and migration intent. Verify metadata
   identity, all five planned originals against their retained backup hashes,
   obsolete originals and absence of every created path. Missing evidence or
   any changed byte requires partial-activation/DB assessment, not a guess from
   `stage=activate`. Preserve this pre-deployment comparison before replacing
   files. The fixed recovery owner automates this comparison when already
   installed; the old c2e2bc77 owner cannot do so.
3. After separate release/deployment approval, verify the complete published
   0.144.4 package and its immutable commit/manifest. Deploy that complete
   compatible release through manual maintenance deployment, preserving
   configuration, galleries/media, data, jobs and backups. Do not copy only
   bootstrap/updater files, repoint the old package or ask Retry to turn its
   pinned 0.144.3 into 0.144.4. The old updater cannot bootstrap this fix itself.
4. Keep maintenance enabled and use **Finish FTP update** in Advanced tools.
   It verifies the whole uploaded release, preserves old jobs/backups/local
   files and completes forward migrations without replaying the 0.144.3
   package. Verify schema, login, gallery reads and package integrity afterwards.
5. Reopen the fixed Updates page, verify reconciled 0.144.4/stable metadata and
   no active old job, restore the intended automatic-update preference and
   scheduling, then perform fresh discovery. On an owned staging copy first,
   exercise an update from the c2e2bc77 ledger with historical rows retained.
   Production acceptance and hosting lock behavior remain operator checks.

Publishing 0.144.4, integrating this branch and deploying production are
separate approvals. No production action or successful live upgrade is claimed
by the supplied snapshot or the disposable regression suite.
