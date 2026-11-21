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

## Administrator version emails

After the updater durably completes an upgrade to a newer version, it attempts
one short email to each saved administrator address. The fixed template contains
the version, its GitHub release link and the project link, in the last remembered
administrator interface language. The completion receipt prevents ordinary worker
replay or status reads from sending again. Reinstalling the same version,
rolling back and beta-commit installs do not announce a new stable version.
There is no mail queue or automatic delivery retry; interruption during delivery
can leave an email unsent. Delivery failure never fails a completed upgrade.

The **Gallery update emails** switch defaults ON for existing and new accounts
and is shared by Features and Account. Accounts with an address get no reminder.
An administrator without an address sees a compact notice after sign-in; its link
opens the existing account email field. Saving a newly entered or changed address
requires the existing current-password check and sends the current-version
template as a sample, even when automatic announcements are OFF. The address is
saved regardless of transport acceptance; a failed sample is reported visibly.
No verification link or viewer-account notification is implemented.

PHP `mail()` is attempted by default, using the host's sender if no application
sender is configured. SMTP is used only when selected and configured in Account.
The shared sender/transport settings also serve password recovery, whose enable
switch remains independent. Transport acceptance is not proof of inbox delivery.
Missing/unknown email schema suppresses announcements and the address reminder;
mail configuration and safe delivery events remain in Account and Admin logs.

## Download source and interrupted transfers

New jobs pin the configured source to one full GitHub commit. The downloader
refreshes PHP's stat cache before measuring an existing partial archive and
after writing response bytes. A supported HTTP Range continuation appends to
that exact source; HTTP 200 replaces the old prefix. Positive short writes in
the PHP stream fallback are errors, and unresolved redirects are not ZIP data.

GitHub's source-archive endpoint may ignore Range. After three incomplete
full-response restarts, the same job looks for the published `production.zip`
instead of repeatedly fetching the same prefix. Three separate bounded metadata
slices read the version from the pinned commit, the published release asset and
the release tag's resolved commit. The tag must resolve to the original full
commit. The asset must have its canonical repository/tag URL, a positive byte
count and a provider SHA-256 digest; drafts, prereleases, duplicate assets and
missing identities are refused. Existing partial bytes are discarded only
after this binding succeeds. Both the ordinary source wrapper and the flat
production ZIP layout remain supported.

The published archive must match its saved byte count and raw SHA-256 before
extraction, independently of the optional per-file publisher verifier. A retry
discards archive-selection and validation receipts while retaining the original
commit. Metadata unavailability follows the existing finite retry policy. An
asset source that also repeatedly ignores Range fails rather than chaining
forever. Unpublished beta snapshots may have no matching release asset; they
cannot borrow another commit's package. Use a complete manual deployment and
**Finish FTP update** if the chosen source cannot be downloaded within hosting
limits. Request timeouts are not extended.

This mechanism starts working only after its complete release is installed.
An older installed downloader still performs the first upgrade using its own
code. Publishing new updater files does not repair an older client's download
loop before it has received those files. Verify that first hop on an owned
copy of the actual installed version, or use the supported complete manual
deployment if it cannot finish.

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

At least 600 seconds must pass from the first caught failure before releasing
an untouched attempt. A later worker opportunity takes the global start and
original worker locks. Before activation, it may release the pointer and keep
the failed job and artifacts. An old job already at `activate` needs complete
original-byte proof: every planned original and backup must match and every
originally absent path must remain absent. Activation or migration intent
requires forward repair.

### Live file replacement

Ordinary HTTP and CLI requests do not consult updater state or take updater
locks. Previous `maintenance.lock`, `cache/updates/activation.json` and rollback
window files are historical evidence. They cannot change the public response,
even if empty, interrupted or unreadable. The updater serializes writes with
the existing active pointer, start lock and per-job worker lock. Its private
HTTP handoffs still require the maintenance token and a single-use ticket.

The prepared package is copied one file at a time into the current installation.
Each intact sibling temporary file is verified and renamed over its destination.
Publish dependencies before the generated module map and entry points.
A retry of the same immutable job skips destinations with the expected bytes.
A release must support the brief coexistence of previous and new code and
schema. Preserve needed old files and compatible function signatures while
older requests can still use them, and use replay-safe forward migrations.

The updater records migration intent before invoking the runner. Before an
ordinary first write it verifies schema and installed migration evidence,
including format-2 ledger timestamps, declared file hashes and explicitly
retired migration files. Installation backups keep original bytes and prior
settings. A failed post-write job can retry the selected package or proceed
through a verified compatible replacement. Live reads and writes make
automatic application-file rollback unsafe. Prior isolated rollback jobs are
preserved as evidence. Database migrations proceed forward.

Migrations and finalization must complete before the updater records
`runtime_committed` and begins resumable cleanup. Public traffic continues
through preparation, activation, retries and cleanup.

Integrity progress uses the durable verified-file cursor and file count for
each package slice. Entering a new checkpoint resets the previous counters and
message, so extraction's 100% is never displayed as verification progress.
The normal interaction stays Update followed by automatic continuation. Details
label the lifetime `attempts` value as **Processing runs** and explain that it
counts short worker requests, including waits, rather than errors or Retry
clicks. Retry continues the same job and retains that count. Unknown local files
outside updater ownership are preserved and do not become
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

After an untouched attempt is released, discovery may install a strictly newer
stable version. It excludes the highest failed stable version and up to 32
recent failed full commits, without deleting any failure evidence. An explicit
manual selection remains possible under its usual validation. A failed job that
cannot be safely repaired still permits independently throttled metadata checks;
it is displayed as failed with guidance, never as ordinary pending/continuing.

No idle worker waits through backoff. Eligible visits, an authenticated
Updates request or hosting cron can provide the next worker opportunity.
Passive status reads do not advance installation. Configure
`php scripts/application_update.php` from hosting cron for idle sites.
Updater work requires a writable private cache and working `flock`; the
website can serve requests independently of those updater resources.

## Already stuck installations

### Overwrite-only FTP completion

Extract the complete verified `production.zip` over the installed application
directory, preserving local configuration, galleries, data and updater records.
The upgraded entry point serves normal pages even if the old job is stuck in
`migrate`. After a successful HTTP response it removes `maintenance.lock` when
hosting permissions allow. Legacy private job records and backups remain.
The 1.4.3 to 1.5.0 transition adds no database migrations.
A background or explicit worker handling a superseded older job releases that
job's active pointer once it observes the newer on-disk version. The previous
job and backup remain available. Avoid overlapping a manual upload with a
worker that is already writing application files.

Use **Reset updater / finish FTP update** when the installed tree needs forward
migrations or an explicit reconciliation. It checks the uploaded installation,
waits for competing installer workers, preserves the previous jobs and backup,
and retires accessible legacy markers after retaining identical bytes. Visitors
continue using their normal routes, including while the action is interrupted.

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
safe ZIP paths, bounded extraction, required application files, installer ownership
and actual migration execution remain enforced. Publisher metadata is not a
prerequisite: an optional inventory selects existing safe files, with safe source
enumeration when it is unavailable. Staging pins the actual selected bytes,
including the manifest itself, independently of diagnostic results.
A failed FTP retry restarts installed-file verification, never archive download.
A new reset supersedes even an interrupted recovery. Old DB effects are retained;
there is no automatic rollback from an explicit recovery with no old DB witness.
Interrupted takeover reuses its journaled zero-slice child, including a partially
linked predecessor, without cancelling that child or overwriting original receipts.

Newer manually installed code takes precedence over an older downloaded job. The installed version is read from disk rather than
an already-loaded old bootstrap constant. New jobs also retain their starting
installed version; legacy jobs retain validated target evidence. Old source
identities and backups remain available for diagnosis after manual completion.

**Pause** requests the next safe checkpoint in every stage, including activation
and migrations. It never kills PHP inside a file rename or SQL statement. Visitors continue using the available files while a job is paused. **Continue update** resumes the
same ID; it does not clear rollback or migration evidence. An in-flight browser
continuation cannot replace a newer explicit paused state. Advanced commit-ID
installation accepts older supported release commits as well as newer commits;
it pins the chosen code. The installer refuses historical packages that would restore the visitor maintenance gate.

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
   The completion job preserves and retires matching legacy marker evidence. Restore scheduling and fresh discovery.

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
