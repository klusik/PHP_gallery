<!--
Project: PHP Gallery
Repository: https://github.com/klusik/PHP_gallery
File: docs/performance/rewrite-observation.md
Module Type: Performance reference
Purpose: Document request-local reuse of rewrite compatibility evidence.
Responsibilities: Record snapshot identity, invalidation and writer exclusions.
Author: Rudolf Klusal
License: MIT License (see LICENSE file in repository)
-->

# Rewrite compatibility observation

Public image URL assembly repeatedly calls
`url_rewrite_should_emit_clean_urls()`. Its compatibility detector reads both
project-root and public-root `.htaccess` markers. `url_rewrite_compatibility()`
retains only its latest observation during a request, avoiding repeated
`is_file`, `is_readable` and `file_get_contents` operations for unchanged inputs.
There is no persistent cache and no per-hit filesystem/stat operation.

Identity compares the exact project root, live enabled preference and every
server field consumed by the detector: `SERVER_SOFTWARE`, `REQUEST_URI`,
`SCRIPT_NAME` (including the actual mount) and `REDIRECT_URL`. Apache module
availability cannot change inside one PHP request. Unrelated configuration,
locale and preview audience fields do not influence this detector; URL owners
still resolve their own state. The administrator preference remains live on
every call. A changed input replaces the single retained observation rather
than growing an identity map. The unchanged detector determines supported,
likely-supported, unknown, disabled and unsupported results.

`app_settings_reset_request_cache()` and `set_url_rewrite_enabled()` discard
the observation. Explicit callers can use
`url_rewrite_reset_request_observation()` after a controlled marker replacement.
External marker changes are intentionally observed on the next request; a
running ordinary render retains its request snapshot. Rewrite choice selects
URL syntax, never authentication, media ownership or serving authorization.

Supported application marker writers advertise a pre-mutation invalidation
contract. Mere declarations can be present in ordinary compiled public route
closures, so their presence alone must not disable reuse. A typed observer reads
PHP's monotonic `get_included_files()` list and scans only new paths. Paths are
normalized to lowercase forward slashes. It recognizes these owner identities:

- Basename `updates_filesystem.php` covers the legacy installation copy and
  its cleanup/removal owner.
- Suffix `/updates_jobs/activation.php` covers current job activation,
  recovery and rollback replacement of managed files.
- Basename `update_server_policy_reconciliation.php` covers dependency-light migration
  reconciliation, including an older updater still executing in memory.

Their respective boolean advertisements are
`UPDATES_FILESYSTEM_INVALIDATES_REWRITE_OBSERVATIONS`,
`UPDATE_ACTIVATION_INVALIDATES_REWRITE_OBSERVATIONS` and
`UPDATE_SERVER_POLICY_INVALIDATES_REWRITE_OBSERVATIONS` in the service namespace.
An advertisement must be defined and strictly true. Every relevant copy,
activation, removal or active-policy replacement boundary sets the request-local
`cms_url_rewrite_writer_active` flag and discards the observation **before** any
possible mutation. The flag remains set after validation failure, partial
replacement, exceptions and settings resets. A writer request never resumes
reuse. The flag also works when an older settings owner is already in memory;
that owner has no new observation cache to invalidate.

An included owner without its advertisement permanently retains the conservative
legacy bypass. This protects older in-memory installation/recovery/rollback and
dependency-light reconciliation code that cannot call new helpers after a file
swap. No updater function or native constant-symbol reference is introduced into
public page dependencies; advertisement lookup uses names only. Observing
included-file paths adds no filesystem checks. Each path is scanned at most once;
retained state is one checked count and a positive legacy-owner flag. A matching
unrelated unmarked filename only conservatively disables the optimization.
Other
Apache guards written by downloads, Trash and log archives live below their
own data/cache directories and do not change either observed marker.
Standalone release tooling runs outside the web request and is observed by
the next request.

The unmarked-owner fallback is permanently supported. Its live deployment
prevalence is unknown; no historical version is assumed retired. Removing it
requires proof that every supported active, recovery, rollback and older
in-memory updater advertises and honors invalidation, plus same-request
replacement regression evidence. Its high-impact regression risk is stale route
syntax after managed policy replacement, while authentication and media guards
remain independent.

The motivating exact-source trace found roughly 57 ms in 120 card detail/media
URL pairs. The compiled Smart route already included `updates_filesystem.php`,
which made the earlier declaration-only exclusion bypass every memo hit. This
change targets that observed mismatch. Its actual benefit still requires a
source-qualified paired comparison; elapsed diagnostic spans alone are not
anonymous HTTP acceptance evidence.

`url_rewrite_settings_test.php` retains strict marker success/failure and route
expectations and adds repeated-hit snapshots, corrupt/missing markers, changed
server/mount/root/preference identities, explicit reset and late-loaded writer
bypass. A marked private fixture is explicitly required after normal HIT
assertions: declaration alone preserves a genuine HIT, while actual writes
invalidate it and latch bypass through subsequent replacements and resets.
A later unmarked fixture independently proves the legacy-owner latch. The
existing updater safety fixture checks the real mutation owners against private
temporary marker files, including failure after a partial preparation. Hosted
native qualification and paired requests are recorded with the exact candidate
in issue #404; no local audit or generator replaces those checks.

No TeX manual impact: existing URL syntax, rewrite preferences, diagnostics,
supported host compatibility and updater operations remain unchanged; this
document records an internal request observation lifetime.
