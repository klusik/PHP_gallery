# Recovery assurance CLI

This tool records recovery evidence; it neither creates production backups nor automatically restores an installation. Start with the hosting provider's existing backup process and the [off-host procedure](RECOVERY_OFF_HOST.md). A fixture PASS is not evidence that a real installation can be recovered.

PHP 8.1+ is required; Composer, a database connection, GD and a web server are not. The CLI never loads application bootstrap or restored PHP, reads `config.php`, imports SQL, invokes migrations, or sends network requests. Run it from a trusted checkout separate from the restored installation. It reuses `app/helpers_files.php` for path containment/normalization and `app/integrity.php::integrity_hash_file()` for normalized release-file hashes. Database access, access policy, login, Trash and migration workflows remain with their existing owners.

## Commands and outcomes

Options require `--name=value`; quote the entire argument when it contains spaces. Example directories must already exist unless described as new. Use private directories outside all web roots with restrictive OS ACLs. Replace example paths with absolute local paths; Windows paths such as `--out=D:\recovery-evidence\set.json` are supported.

```text
php scripts/recovery.php help
php scripts/recovery.php template --kind=set --out=/private/set.json
php scripts/recovery.php inventory --set=/private/set.json --out=/private/inventory-01.json
php scripts/recovery.php template --kind=isolation --set=/private/set.json --out=/isolated/.recovery-isolated.json
php scripts/recovery.php template --kind=observations --set=/private/set.json --root=/isolated --out=/private/observations.json
php scripts/recovery.php validate --set=/private/set.json --root=/isolated --observations=/private/observations.json --out=/private/validation-01.json
php scripts/recovery.php drill --work=/private/new-fixture-directory
```

Commands create output files exclusively and refuse to replace evidence. Use new filenames for attempts. `validate` refuses evidence output inside the restored tree. `drill` creates a new directory and retains its synthetic artifacts; it never reuses or deletes an existing directory.

| Exit | Meaning |
| --- | --- |
| 0 | PASS within reported `coverage`; template creation also returns 0 |
| 1 | FAIL, invalid input, isolation refusal, or I/O failure |
| 2 | INCOMPLETE: required evidence or agreed targets are missing |

Read `coverage`, `recovery_readiness` and each check's `source`. `inventory_only` assesses supplied receipts; `fixture_only` exercises tooling. A complete `isolated_files_and_operator_evidence` result reports `operator_attested`: database and browser results were supplied by an operator. No result is labelled independently verified production recovery. A previous successful restore does not substitute for current checks.

Malformed input produces a fixed non-sensitive error. Check field names/types, timestamps, marker, permissions, disjoint paths and existing output. Comparison failures are recorded in evidence. Failed originals are identified by one-based catalog ordinals, capped at 50, without filenames.

## Complete the recovery set

`template --kind=set` creates unknown/false/null defaults. Edit that private JSON before inventory; it must contain exactly the generated fields. Never paste credentials, tokens, SQL, raw provider output, URLs or free-form notes. Keep provider receipts and human notes separately protected.

| Field | Meaning |
| --- | --- |
| `schema` | Integer `1` |
| `set_id` | Opaque label: 1–64 ASCII letters/digits/underscores/hyphens, starting with a letter/digit |
| `recovery_point` | Consistent snapshot instant, strict UTC `YYYY-MM-DDTHH:MM:SSZ` |
| `backup.method` | `provider_snapshot`, `maintenance_window`, or `unknown` |
| `backup.consistent` | Operator verified DB/files represent one point |
| `backup.off_host` | Independently retrievable off-host copy exists |
| `backup.retention_days` | Positive integer or null |
| `backup.previous_restore_verified` | Boolean historical fact only |
| `targets.rto_seconds`, `targets.rpo_seconds` | Explicitly agreed nonnegative integer limits or null |
| `application.version` | Exact release version or `unknown` |
| `application.manifest_sha256` | Raw lowercase SHA-256 of that release's `app/core-manifest.json`, or null |
| `counts` | Snapshot counts: live `images`, `galleries`, `trash_entries` |
| `originals` | Complete live image-file catalog, one record per counted image |

All component keys are required: `database`, `originals`, `configuration`, `data`, `custom_assets`, `application`. Each has exactly `state`, `snapshot_id`, `receipt_sha256`. State is `present`, `absent`, `unknown`, or (only for custom assets) `not_used`. A present component needs the same snapshot ID as `set_id` and a lowercase SHA-256 receipt. Different IDs are rejected. Missing required components fail; unknown components or missing receipts remain incomplete. Represent required empty storage such as `data/` in its receipt.

A component digest identifies a protected provider/operator inventory receipt, not secret-file contents. The CLI never reads those receipts. Matching IDs attest coordination; they do not prove that the provider froze writes correctly.

Each original entry has `path` and `sha256`. Example path: `galleries/trip/photo.jpg`. Supply a lowercase raw SHA-256 for sampled originals, or null for presence-only checks. Include every live catalog image. Choose representative samples before restoration across galleries, dates, private content, formats and file sizes; hashing all originals is supported. Use trusted source snapshot hashes, never hashes invented from the restored target.

Catalog length must equal `counts.images`; paths must be unique. A nonempty catalog needs at least one hash sample. Companion originals, gallery assets and Trash payloads still belong in the backup even if not live image rows. Do not list thumbnails as originals. Every listed file is checked for presence and every supplied hash is compared. The validator does not scan for unregistered extra files; reconcile them during operator checks.

Supported catalog suffixes: JPEG, PNG, GIF, WebP, HEIC, HEIF, DNG. Limits are 100,000 images and 32 MiB per input JSON; larger installations require a reviewed extension, not a truncated catalog. Staging uses `galleries/`. Copy custom external gallery storage into this layout and configure only the isolated app accordingly. Physical copies are required: symbolic links, junctions, hard-linked files, traversal and Windows alternate streams are refused. Never substitute live external storage.

## Isolated validation

1. Freeze the recovery set after obtaining receipts and agreeing targets. Preserve it with the off-host backup and restricted catalog.
2. Complete the off-host isolation procedure before starting restored PHP or connecting to a database. The CLI does not configure firewalls or disable anything.
3. Generate `.recovery-isolated.json` inside the restored root. It contains a new `run_id`, `set_sha256` and six false controls. Independently verify, then set all controls true: `network_egress_blocked`, `email_disabled`, `jobs_disabled`, `integrations_disabled`, `uploads_isolated`, `database_isolated`. This is an attestation, not enforcement.
4. Generate observations using the set and isolated root. Record `started_at` when retrieval/preparation began; do not restart the clock after slow setup.
5. Optionally validate files first without observations. That deliberately returns INCOMPLETE because application behavior has not been established.
6. Perform the operator checks below through existing application owners. Use a second disposable restored copy for upload/Trash mutations, or undo those test changes before final comparison. Record baseline counts before exercises.
7. Complete observations and record UTC `completed_at` after recovery checks. Hash the separately stored sanitized operator report into `evidence_sha256`; the CLI never reads the report.
8. Validate into a new evidence file. Keep failed/incomplete attempts. Editing the set or changing run ID invalidates marker/observation bindings and requires a new review.

All observation fields are required. Counts and `orphan_images`, `orphan_galleries`, `pending_migrations` accept null while unknown. Counts must match baseline; orphan and pending-migration counts must be zero. Each named check is `pending`, `pass` or `fail`:

| Check | Minimum private evidence |
| --- | --- |
| `database_import` | Successful provider import into isolated target; baseline counts |
| `gallery_relationships` | Parent/child, image/gallery and representative cover/tag/translation relationships; no orphan rows/files |
| `private_gallery_denial` | Fresh anonymous browser denied protected page, original, thumbnail, metadata and download; descendants protected |
| `admin_login` | Real login/logout with isolated credentials; no session/token recorded |
| `trash_restore` | Existing Trash service restores subtree, metadata and permissions; collision cannot overwrite occupied path |
| `migrations_current` | Existing ledger/readiness current for exact restored release |
| `configuration_rekeyed` | Isolated config supplied securely; production destinations/credentials disabled or replaced |
| `custom_assets` | Branding/CSS/gallery assets restored, or documented none configured |
| `public_routes` | Representative anonymous pages and authorized media work |
| `thumbnails` | Both permanent renderers work; derivatives regenerate through existing owners |
| `uploads` | Synthetic upload succeeds only in isolated storage through existing workflow |

Protected-denial checks must establish that bytes and metadata did not leak; a redirect or blank response alone is insufficient. Missing protected/Trash examples mean pending coverage. Prepare representative examples in an approved isolated source before its backup, not in production to satisfy a drill. Use existing reports/model-backed diagnostics for counts and relationships; this tool adds no SQL exporter or alternate policy implementation.

`backup_age_seconds` is snapshot-to-validation age. `data_loss_window_seconds` is snapshot-to-start when supplied, otherwise snapshot-to-validation: a conservative drill proxy, not measured lost writes in an incident. `recovery_duration_seconds` is completion minus start and is compared to RTO. Future/inverted timestamps fail. Real incident loss must be assessed against its last consistent recovery point separately.

## Synthetic drill, tests and limitations

The drill copies two synthetic PNG originals, an inert application manifest, synthetic JSON catalog and Trash payload from its backup into a new restored tree. It creates a damaged variant to prove detection of a corrupt and missing original, checks synthetic catalog relationships/orphans, and moves a synthetic Trash image into a fresh gallery directory.

Artifacts: `set.json`, `backup/`, `restored/`, `damaged/`, `restored-evidence.json`, `damaged-evidence.json`, `drill-evidence.json`. Fixture receipts and its off-host flag are synthetic inputs. No SQL import, database process, application login, protected HTTP request or off-host transfer occurs. The Trash exercise is a file move, not an application transaction. Nested evidence also declares fixture coverage and pending operator checks. Its restored-file snapshot precedes the synthetic Trash move.

`tests/recovery_assurance_test.php` exercises CLI outcomes, repeatability, damaged/missing files, canonical integrity normalization, count discrepancies, orphan/migration observations, stale binding, targets, path refusals, exclusive outputs and redaction. It creates and cleans only unique temporary fixtures. Link cases run when the host permits creating links.

The existing central audit discovers this `*_test.php`; no audit-registry entry or special PHP extension is needed. Agents normally verify through the central audit; focused execution is for new-test development or specific failure diagnosis. The coordinating maintainer owns the full audit and manifest generation/check after integration. This task changes neither owner.

Evidence omits credentials, paths, filenames, arbitrary text and exception details, but counts/dates/fingerprints remain operational information. Protect it. Exclusive creation prevents accidental overwrite, not malicious tampering; retain evidence in immutable/versioned off-host storage and apply signing/access controls there as appropriate.
