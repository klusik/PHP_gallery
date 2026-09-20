# Replay-safe create and classic upload

Project: PHP Gallery  
Author: Rudolf Klusal

## Request contract

Generated empty-gallery, existing-gallery upload, and create-with-upload forms
carry an enabled hidden field named operation_key, marked data-admin-operation-key.
It contains 32 random bytes encoded as 64 lowercase hexadecimal characters.
It identifies an intent; it is not an authentication token.

The same authenticated actor, key, operation kind and semantic payload return the
original completed response and stable IDs. A fresh key means an intentional new
operation: normal suffix selection still allows deliberate duplicates. There is
no title or filename deduplication.

The admin_new_gallery POST and classic admin_upload modes existing/new require
keys, including empty create-with-upload requests. The existing upload-preferences
action is outside this contract. Prepared ZIP batches retain their established
session/batch owner; this ledger does not replace prepared-batch replay.

Missing or malformed keys return HTTP 409 with operation_key_required before
claiming or changing a target. There is deliberately no unprotected compatibility
path for old POST clients. Reload a generated form and retain its key for that
intent. Fixtures should use GalleryWorkflow\Http::operationKey() to read actual
markup, reuse that key for retries, and fetch fresh keys for deliberate new work.
Neither CSRF nor session identifiers substitute for operation keys.

Authentication and CSRF run before claiming or returning replay on every request.
A newly authenticated session for the same administrator can retry the key with
current CSRF. Another actor cannot obtain that actor's result. Completed replay
freshly checks the original gallery, its parent context and uploaded image
ownership. Missing or relocated results cause a refusal, never recreation or
title-based replacement.

## Ownership and durable lifecycle

Controllers normalize requests, enforce auth/CSRF, prepare views, map domain
reasons to HTTP and send results. The admin_operation_keys service owns semantic
fingerprints, admission, bounded response projection and reconciliation policy.
Its model exclusively owns ledger SQL and advisory locks. Both controllers
explicitly require the service; it requires its model, schema inspection and
central constants. No extra top-level loader registration is required.

Migration 202609200003_admin_operation_keys.php creates an InnoDB ledger with
unique (actor_id, key_hash) identity. Explicit available table, column and primary
index observations are required. In addition to generic named-index existence,
the model freshly reads the exact PRIMARY definition from the current database:
exactly actor_id then key_hash, sequence 1/2, NON_UNIQUE zero and SUB_PART null for
both columns. The query reads at most three rows, including one extra-column
lookahead. Missing, unrelated, reordered, prefixed, nonunique or wider indexes
lack the required guarantee. Incomplete metadata and query failures are unknown;
neither native exceptions nor raw definitions reach callers. Both missing and
unknown guarantees use operation_storage_unavailable before lock acquisition or
claim insertion. Generic schema-inspection semantics remain unchanged. Requests
never bootstrap or repair this storage. Concurrent privileged schema changes
still require ordinary coordinated maintenance; this preflight is not a DDL lock.

One non-waiting connection lock serializes each database/actor/key. Atomic insert
preserves any previous claim without overwriting it. Pending is durably committed
before target work; claims and completion must not join a target transaction.
Target operations may use their own transactions while the advisory lock remains.

| Retained state | Retry behavior |
| --- | --- |
| No row, lock acquired | Insert an owned pending claim and execute once. |
| Another connection owns the key | Refuse operation_pending; do no target work. |
| Completed, same binding | Revalidate references; return the original stored envelope. |
| Same key, different operation/payload | Refuse operation_payload_conflict. |
| Old pending or needs_reconciliation | Refuse; never reclaim or execute replacement work. |

Create, classic upload and combined create/upload have distinct operation kinds.
Fingerprints include normalized creation fields and parent, existing-upload
target, thumbnail choice and accepted file sequence. Each file contributes its
submitted name, actual size and streamed SHA-256 digest. Temporary paths, client
size claims, CSRF, credentials, sessions, source-page URLs and presentation-only
flags are excluded. Only the final hash is stored, not plaintext input or a
recoverable multipart body.

Completion is projected, validated and durably stored before JSON output or the
no-JavaScript redirect. Both initial output and replay use that same value:
the canonical ok/message/mutation/panel/contexts/fallback envelope plus bounded
identities, URLs and counts. Raw processing events and native errors are replaced
by safe typed diagnostics and compatible translated summaries, not silently
removed from the first response. Credential-shaped fields and unrecognized URL parameters are
rejected. Canonical query-mode public_path URLs are supported. A posted source URL
contributes only bounded positive gallery_page/photo_page values or an approved
clean pagination suffix of that exact canonical gallery, never an arbitrary path.
Mutation responses are private and no-store.

Immutable ADMIN_OPERATION_* limits live in app/policy_constants.php: 32 bytes of
key entropy, 256 files per classic operation, 1 MiB fingerprint serialization and
256 KiB durable response JSON. These are not upload-size or image-decode settings.

Classic upload holds the global gallery writer lease from before creation/file
mutation through ingestion, optional thumbnails and ledger completion. Combined
upload verifies ingestion and thumbnail metadata prerequisites before creation.
The three service facades store_uploaded_gallery_images(),
store_uploaded_gallery_cover() and store_uploaded_gallery_branding_asset() also
take reentrant leases for other callers, holding them through their complete
scan/sidecar/persistence bodies. Their _owned functions are internal, not alternate
entry points. Parent-owned creation wrappers take nested leases. Completed replay
takes no mutation lease.
Every upload storage body reloads its gallery with find_gallery(id, true) under
the lease. The classic controller also replaces its pre-claim cached row after
acquiring ownership, before using mutable paths or preparing result metadata.

### Partial-success feedback

The canonical upload_diagnostics list carries closed codes scan_failed,
thumbnail_failed, rename_warning and rename_failed, with count, filenames and
filenames_omitted. Filename lists contain at most 32 UTF-8 image basenames of at
most 255 bytes; path/URL/control-bearing entries are withheld and counted.
The ordinary filenames result is similarly validated, up to 256 names, with an
explicit filenames_omitted count. Numeric diagnostic counters are capped at
1,000,000. These bounds are centrally owned ADMIN_OPERATION_* constants.

Existing classic UI consumers retain their shapes:

- scan_failed_filenames and thumbnail_failed_filenames contain safe names.
- rename_warnings, rename_failures and thumbnail_errors are string arrays
  containing generated translated summaries, never original exception text.
- upload_events contains at most seven generated summary/issue events with
  code, count, filenames and message. The existing progress log can display
  message without new JavaScript wiring. Raw elapsed times, context and native
  event messages are not copied.

All seven diagnostic messages have EN/CS/DE/SV translations. First delivery and
replay carry identical stored diagnostics, including the original language;
replay does not regenerate translations. Older completed rows retain exactly
what was originally stored: missing historic diagnostics cannot be reconstructed.

## Interrupted operations and retention

There is no automatic expiry, deletion, retry timer, lease stealing, or transition
from pending back to new. Deleting a completed row would allow an old key to run
again, so ordinary cleanup must not remove these rows. Pending/ambiguous rows are
retained indefinitely until explicitly investigated. This version has no pruning
job or web reconciliation UI; the operator CLI below supplies counts and actions.

An exception after admission marks needs_reconciliation when possible. If that
update cannot be acknowledged, the earlier durable pending row still blocks
replacement execution. If completion committed but its acknowledgement was lost,
failure handling never overwrites completed; a later retry can recover its result.

This is replay protection, not an atomic filesystem/database transaction or a
physical create/upload journal. Interrupted ingestion may leave files/rows
without enough evidence to reconstruct success. The image-move journal remains
separately owned and must not be used to infer upload completion. This ledger
never deletes originals or guesses success from similar names or elapsed time.

Explicit recovery requires:

1. Preserve the original key, actor and row, and verify the worker has stopped.
2. Establish the original effects using trustworthy independent catalog,
   filesystem and diagnostic evidence. Check exact IDs, bytes, parent ownership,
   sidecars, derivatives and partial work. Take the usual verified backup before
   separately authorized corrective maintenance.
3. Only after establishing the exact successful result, a trusted maintenance
   caller may invoke admin_operation_reconcile_completed(actorId, key,
   payloadHash, response). This unrouted service verifies binding and references,
   excludes a live worker and attaches a bounded original canonical response.
   It never reruns work. The caller must prove physical completion; a hash-only
   ledger cannot establish it.
4. Leave the row blocked if evidence is insufficient. There is no clear-key-and-
   retry shortcut. Use a fresh key only for a consciously separate intent after
   resolving the prior outcome.

Services expose domain reasons, not HTTP fields. The controller maps invalid
payload to 422, unavailable storage/unknown outcome to 503, and required key,
pending, changed payload, reconciliation and unavailable original result to 409.
All eight admin.operation.* reasons are translated in EN/CS/DE/SV JSON catalogs.
Native database exceptions and credential data must not appear in them.

### Operator CLI

Run these commands only from the intended installation, using its normal trusted
maintenance account. Help does not load configuration. Listing and inspection are
read-only; they do not apply migrations or repair missing storage.

~~~text
php scripts/reconcile_admin_operations.php --help
php scripts/reconcile_admin_operations.php --list
php scripts/reconcile_admin_operations.php --list --after=ACTOR:KEY_HASH
php scripts/reconcile_admin_operations.php --inspect=ACTOR:KEY_HASH
~~~

The list returns exact pending, needs_reconciliation and unknown counts, up to
50 metadata rows and next_cursor. Pass a returned cursor unchanged to inspect
later pages. Inspection identifies the actor, key_hash, payload_hash, operation,
state and timestamps. It does not reveal the worker ownership hash, request
payload, credentials, original response body or paths. These operation/payload
digests are correlation identifiers, not authentication credentials.

After independently proving the original effects, prepare a private UTF-8 JSON
evidence file outside the web root. Its only top-level keys are actor_id (integer),
key_hash, payload_hash and response. Copy both 64-character hashes and the actor
from inspection. Prefer an independently retained original canonical success
receipt as response. If no receipt exists, an operator must establish and
construct the exact result; neither the CLI nor a matching title proves success.

For illustration, a verified empty-gallery creation receipt has this shape.
Replace every illustrative ID, hash, URL and postcondition with verified original
values. Placeholder hashes deliberately fail validation.

~~~json
{
  "actor_id": 12,
  "key_hash": "REPLACE_WITH_INSPECTED_64_CHARACTER_KEY_HASH",
  "payload_hash": "REPLACE_WITH_INSPECTED_64_CHARACTER_PAYLOAD_HASH",
  "response": {
    "ok": true,
    "message": "Original gallery creation independently verified.",
    "mutation": {"type": "gallery.create", "entity": "gallery", "action": "create", "entity_ids": [321]},
    "panel": {"workflow": "gallery-edit", "refresh_url": "/index.php?page=admin_edit_gallery&id=321", "keep_open": true},
    "contexts": [{
      "type": "gallery_index",
      "gallery_id": null,
      "render_url": "/index.php?page=home",
      "render_mode": "preserve_view",
      "postcondition": {"type": "gallery_membership", "gallery_id": 321, "parent_gallery_id": 0, "present": true, "count": 8}
    }],
    "fallback": {"redirect_url": "/index.php?page=admin_edit_gallery&id=321&created=1"},
    "gallery_id": 321,
    "parent_gallery_id": 0,
    "gallery_url": "/index.php?page=gallery&public_path=verified-gallery"
  }
}
~~~

Upload receipts additionally need the verified image_ids and original upload
counters/filenames. Classic upload uses mutation type image.upload with image
entity IDs; combined creation/upload uses gallery.create_with_upload with the
original gallery ID. Preserve the original affected contexts, panel and fallback.
Use the shapes produced by app/helpers_mutation.php; do not invent identities,
inflate counts, mark partially completed physical work successful, or put SQL,
native exception messages, credentials or source-system paths in the evidence.

~~~text
php scripts/reconcile_admin_operations.php --complete="/private/verified-operation.json"
php scripts/reconcile_admin_operations.php --complete="/private/verified-operation.json" --apply
php scripts/reconcile_admin_operations.php --inspect=ACTOR:KEY_HASH
~~~

The first command is a dry-run: validated_only means the document, retained
binding and current references passed checks, not that physical completion was
proved. After independent verification, --apply changes only the selected
pending/needs_reconciliation row to completed and stores its safe response.
Both paths refuse an active key owner, changed fingerprint or invalid result.
The CLI prints status and stable result IDs, not the response body. Repeated apply
refuses instead of overwriting completed; inspect its state after an uncertain
acknowledgement. A retained browser key can then replay the original result after
normal authentication/CSRF checks. No command creates, uploads, deletes rows,
expires keys, rolls back files or automatically retries work.

The reusable admin_operation_pending_report() service is available for a future
Admin health adapter, but this slice does not wire a dashboard query. Counts are
currently requested only by explicit operator inspection.

## Browser and form integration

Hidden keys work with native POST and new FormData(form). Custom multipart
reconstruction must retain operation_key, including the create step preceding
prepared upload. Do not reuse prepared session/batch identifiers as this key.

The integrated browser owner is admin-operation-keys.js, used by the side-panel,
classic multipart and prepared-create handlers. See the
[create/upload intent-retention contract](ADMIN_PANEL_LIFECYCLE.md#createupload-intent-retention)
for exact forwarding, per-chunk keys, confirmed-completion rotation, original-form
ownership and prepared-batch retry behavior. The first classic request uses the
rendered key; subsequent chunks have distinct retained keys. Prepared ZIP requests
keep their existing batch owner rather than acquiring operation-ledger keys.

Uncertain work retains the original form, operation keys, scalar snapshot and
File objects behind the inline unresolved-intent guard. It is not copied into
the ordinary title/description draft map. Network/authentication failures and
changed input do not silently rotate keys or launch replacement work. Exact
retry requires the original intent and current CSRF; the key module does not
provide a login/CSRF-refresh workflow or cross-document recovery.

The browser owner's import/cache contracts have been corrected, according to the
parent integration checkpoint. The new full browser integration run has not yet
been executed at this checkpoint; server fixtures and source/cache assertions
must not be presented as that browser acceptance evidence.

No-JavaScript success replays the original redirect. Failure rendering retains a
valid submitted key but does not restore full scalar drafts or file-input
contents. Retry requires the same semantic fields/files. Loading a fresh form
is not recovery of unknown server work.

Create views consume parent_picker_html prepared by the discovery controller
using render_gallery_parent_picker(prefillParentId). They call no picker service
and no longer build full-catalog parent selects. Existing create form-model
construction does not materialize a gallery catalog for this purpose.

## Verification and parent registration

tests/admin_operation_keys_test.php runs real controllers, service and model
against isolated SQL/auth/domain seams. It covers mandatory keys, current
auth/CSRF, actor/payload binding, original responses, deliberate duplicates,
temporary-path-independent hashes, changed bytes, combined preflight, writer
lifetime, missing/unknown schema, interrupted work, lost acknowledgement and
explicit reconciliation. It renders actual no-JavaScript create fields and
checks the bounded picker. This is not real database crash/concurrency evidence.

tests/upload_writer_ownership_test.php runs all three real upload facades and
their lock model with missing-gallery/isolated SQL seams. It checks entry
exclusion, failure release and reentrant ownership, not successful cover/branding
replacement.

tests/admin_operation_keys_http_test.php uses the strictly owned disposable
workflow runner. It checks actual rendered keys, authenticated create/classic/
combined HTTP replay, durable JSON, reauthentication, original hashes, deliberate
duplicates, another database connection owning the operation/global writer lock,
old pending retention and no-JavaScript redirect replay. It does not inject TCP
disconnects, kill an uploading worker, or prove simultaneous mutating workers.
Without the explicit fixture it reports SKIP (BLOCKED when required), not PASS.

tests/admin_operation_diagnostics_test.php verifies preserved legacy UI shapes,
safe filenames and omissions, four-language message registration, rejection of
raw diagnostics and identical replay after a language change.
tests/admin_operation_maintenance_test.php verifies model-backed bounded listing,
counts, cursor inspection, evidence bounds, dry-run, explicit apply, worker
exclusion and indefinite retention with isolated SQL seams.
tests/admin_operation_primary_key_test.php exercises the real bounded model read
and service admission against independent named-object/definition fixtures. It
covers integer/string metadata, missing/unknown inspection, unrelated/reordered/
prefixed/nonunique/extra-column PRIMARY definitions and fresh reinspection after
a cached named-index observation. Refusals must attempt neither locks nor claims.
This is not a real MySQL malformed-table fixture or concurrent DDL experiment.

All six use ordinary PHP regression discovery. The parent should register
admin_operation_keys_http_test.php with timeout 180 in php_test_requirements in
scripts/audit_registry.php. The existing side-panel create PHP fixture supplies
a key and checks exact replay. Node create-server assertions check claim/replay/
durable-completion order while preserving the parent's detached-panel guard.

The coding agent ran the four isolated tests successfully and exercised CLI
--help without loading configuration. The HTTP entry previously skipped without
a disposable fixture; the parent subsequently reported quick4 HTTP/crash/replay
passes before this diagnostic/maintenance/fresh-read follow-up. Those results
do not qualify the later changes. Real configured CLI dry-run/apply and new
diagnostics in a browser still require parent integration verification. The
parent owns central audits, migration application and the final manifest.
