# Coordinated off-host recovery procedure

The required outcome is a measured restore of a representative installation into isolation, including access protection and application behavior. File copies, matching hashes and synthetic fixture PASS alone do not meet it. Adding this procedure or the [recovery CLI](RECOVERY_ASSURANCE.md) does not demonstrate actual production recovery.

## Inventory the existing process first

Ask the hosting/backup owner for the mechanism, retention, last successful job, consistency guarantee, off-host location under separate failure/credential control, encryption/key custody, restore access and most recent restore report. Record bounded receipts rather than credentials or raw console output. Confirm another authorized operator can retrieve/decrypt the backup without the primary host or its credentials.

Reuse the existing provider process when it supplies this coverage. The CLI is not a backup scheduler, uploader, repository or retention engine. Unknown answers stay unknown. Agree numerical RTO (elapsed recovery time) and RPO (maximum acceptable loss window); the fixture's example limits are not production targets.

## One recovery set

Give all components one snapshot ID and consistent recovery point:

| Component | Preserve and verify |
| --- | --- |
| Database | Entire database, migrations, catalog, access state, settings, translations/tags, Trash records, authentication and optional subsystem rows |
| Originals | Configured gallery tree, original/companion files, nested structure, covers, gallery-owned assets and access-policy files |
| Configuration | Config, secrets and external key material through encrypted backup/vault custody; never CLI evidence contents |
| Persistent data | Relevant `data/`, especially `data/gallery-trash/`, `data/admin-log-archives/`, and configured external storage |
| Custom assets | Installation branding/theme files, custom CSS and locally owned assets outside galleries |
| Application | Exact release/archive, version, managed-file manifest, migrations, deployment recipe and PHP/extensions |

Trash rows and payloads are one unit. Older installations may retain `cache/gallery-trash/`; review existing Trash storage behavior before omitting it. Log archives contain history removed from the live database. Neither is disposable thumbnail cache. Do not assume all of `cache/` or `data/` is regenerable.

Generated thumbnail sizes/formats, authorized download artifacts and ordinary render caches may be rebuilt from verified originals through existing services. Originals, metadata, access policy, Trash payloads, assets and logs cannot. Include derivatives or a warm-up plan if rebuilding would breach RTO. Measure representative regeneration count/bytes, elapsed time, peak storage, CPU/I/O limits and hosting cost in isolation; leave unknown values explicit. Exercise both permanent `progressive` and `responsive` renderers.

## Consistency and off-host storage

1. Prefer provider snapshots explicitly coordinating database and filesystem state across all storage volumes. Crash-consistent disks alone do not establish application consistency.
2. Otherwise arrange an approved bounded maintenance window. Stop browser/classic uploads, WebDAV, edits/deletes/Trash, ingestion/migration, scheduled/cron workers, request-triggered maintenance, integrations and updater activation. Block new work and let active writes finish. Name the operator who reopens service.
3. Use the provider's established database backup and filesystem snapshot/export under that write barrier. Record start/end and shared recovery point. Independently dumping SQL and copying a changing tree is insufficient.
4. Capture component receipts, exact application manifest, baseline counts and original sample hashes while source state is stable. Retain the previous known-good set if components are uncertain or partial. Never derive expected evidence from the later restored files.
5. Transfer with existing encrypted off-host tooling; verify provider checksums/receipts there and confirm key material remains separately recoverable. Resume writers after the provider's safe snapshot/export point rather than waiting unnecessarily for network transfer.
6. Retain multiple generations, verify immutability/deletion protections, and keep evidence outside the host's failure domain. Hashes do not prevent simultaneous replacement of payload and receipt.

These procedures require the hosting owner's authority. The CLI does not stop production jobs or perform these mutations.

## Restore into isolation before starting PHP

Use a fresh VM/container or separate machine/database without production mounts, aliases or credentials. Keep original encrypted media and receipts intact and read-only where practical.

Enforce network egress denial at host/network level. Block outbound SMTP, HTTP/API/OAuth callbacks and production database destinations. Remove restored cron/systemd/Task Scheduler entries, workers and web-cron triggers. Disable request-triggered maintenance, mail and integrations through existing supported controls. Use an isolated local mail sink if needed. Bind HTTP to a private test interface with restricted access and a disposable hostname without public DNS or production cookies. Barriers must exist before loading restored PHP.

Retrieve with established provider tooling and restore only into the fresh target. An authorized operator securely supplies isolated configuration without printing it; this CLI never opens it. Replace/disable production database, SMTP/API/OAuth/WebDAV destinations and credentials. Disable external upload clients and give tests disposable local storage. Ensure PHP, image workers and other processes share isolation; the CLI marker cannot enforce it.

Restore the database and original application version before upgrades. Restore originals, persistent data, assets and web-server protections from the same set. Verify ownership/permissions and that configuration, `data/`, Trash and archives are not publicly downloadable on the chosen web server.

Generate the marker and observations following [RECOVERY_ASSURANCE.md](RECOVERY_ASSURANCE.md). Use existing model-backed reports/diagnostics for counts and relationships. Never point diagnostics or migration tools at production to obtain drill evidence. If the restored release actually needs schema work, use its existing migration runner only after isolation, recording pre/post state and reassessing the baseline. Validation itself does not migrate anything.

Exercise anonymous browsing, protected-page and media denial, administrator login/logout, catalog counts/hashes, gallery relationships, migration readiness and representative Trash restoration. Include configured NSFW/share/access behavior. Verify assets, derivative regeneration and a synthetic upload. Use a second disposable copy for mutations, or restore its test changes before final baseline comparison.

Record elapsed recovery time from retrieval/preparation through all checks, backup age, agreed targets, pass/fail/pending observations and sanitized evidence receipts. Unavailable checks remain pending. A responsible operator reviews automatic comparisons alongside private browser/database evidence before accepting the drill. Repeat on an owner-agreed schedule and after significant hosting, storage, backup or schema changes.

## Update rollback decision

Updater rollback restores application files. It does not reverse database migrations or changes to photographs, Trash and other persistent state.

| Situation | Decision |
| --- | --- |
| Pre-activation failure, live state unchanged | Review updater state and use its existing retry/cancel workflow |
| Activated files, prior code demonstrably compatible with current schema/data | Existing file rollback may apply; verify compatibility and smoke checks in isolation |
| Incompatible migration, or compatibility unknown | Keep writes stopped; recover a complete coordinated set into a new isolated target and validate |
| Lost host, missing originals, inconsistent database/files | Restore the complete set; file rollback alone cannot repair it |

Do not infer compatibility from version numbering, updater success or a matching manifest. Review migrations/release changes with the existing updater owner. Preserve failed-installation evidence and recovery media. Production cutover, rollback, DNS changes, credential rotation and acceptance of data loss require the incident owner's separate decision; the CLI does not authorize or implement them.

## Handoff and retention

Keep the recovery inventory, provider receipts, original sample hashes, isolation attestation, all validation attempts, operator-report digest and acceptance in restricted off-host storage. Keep secrets under existing encrypted custody rather than in the evidence bundle. Record unresolved coverage and breached targets explicitly.

This implementation establishes repeatable tooling behavior with synthetic fixtures. Real recovery assurance remains pending until an operator retrieves a representative off-host set, restores it into isolation, verifies database/application behavior, and meets agreed targets.
