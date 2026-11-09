<!--
Project: PHP Gallery
Repository: https://github.com/klusik/PHP_gallery
File: docs/API.MD
Module Type: Public gallery-key API reference
Purpose: Specify the original pgu_ HTTP integration contract and examples.
Responsibilities: Cover all intended key-authenticated operations; exclude
  automatic browsing and keep the four localized manual chapters aligned.
Author: Rudolf Klusal
License: MIT License (see LICENSE file in repository)
-->

# Gallery-scoped API-key reference

Complete reference for the original gallery-key protocol. Authentication does not expose automatic browsing. All examples contain synthetic identities; use only galleries and keys that you are authorized to operate.

## Contents

- [Scope and support boundary](#scope)
- [Operation index](#operation-index)
- [Connection, keys and request conventions](#connection)
- [Upload photographs](#upload)
- [Inventory: compare supplied file candidates](#inventory)
- [Partial results, timeouts and recovery](#recovery)
- [Revoke the current key](#revoke)
- [Specialized AI worker contract](#ai-contract)
- [Claim one AI job: ai_next_job](#ai-next-job)
- [Renew an AI claim: ai_heartbeat](#ai-heartbeat)
- [Complete or fail an AI job: ai_complete](#ai-complete)
- [Fetch the claimed AI input: ai_asset](#ai-asset)
- [Look up a PDF report receipt: flight_report_status](#flight-report-status)
- [Attach a PDF report: flight_report](#flight-report)
- [Migration: authority and data contract](#migration-contract)
- [Export the manifest](#migration-manifest)
- [Download one source asset](#migration-asset)
- [Download a source ZIP package](#migration-package)
- [Prepare the destination job](#migration-receive-manifest)
- [Upload one asset to the destination](#migration-receive-asset)
- [Upload a planned ZIP package](#migration-receive-package)
- [Reconcile destination progress](#migration-receive-status)
- [Complete the destination job](#migration-receive-complete)
- [HTTP errors, limits and compatibility](#errors)
- [Complete Python upload, inventory and migration example](#complete-client)
- [Implementation map and maintenance](#source-map)

<a id="scope"></a>

## Scope and support boundary

This reference describes the existing gallery-scoped `pgu_` HTTP API. It is
intended for independently written clients, not only the Windows uploader. The
reviewed baseline is PHP Gallery `1.0.4`, commit
`150160d3771f97f5b96dab86e98ac6fe5365ccb4`. This identifies the source checked
for this edition; it is not runtime version negotiation or a promise that every
older installation has every optional action.

Only explicit operations authorized by a gallery key belong here. Automatic
browsing, server-wide gallery discovery/listing/search, filesystem discovery,
Watcher traversal, browser-approved linking and the resource API from issue #345
are not part of this public contract. WebDAV is a separate protocol with
separate credentials. An inventory request compares caller-supplied file
candidates; it is not an endpoint for browsing all galleries. Internal
post-upload registration is server implementation, not an exposed scan command.

The key fixes the upload target. It is not necessarily upload-only: when gallery
migration is enabled, that same key can export the authorized gallery and its
descendants, and a destination key can import a new child tree. Do not hand it
to an untrusted party as a write-only credential. Choosing
`include_subgalleries=0` limits one export request, not the authority of the key
itself. No new permissions, endpoints or server behavior are introduced by this
documentation.

<a id="operation-index"></a>

## Operation index

The first nine actions below share index.php?page=upload_automation_upload.
Migration uses the eight individually named routes. Every call requires the
appropriate gallery key; specialized actions add their own gates. The document
covers 17 operations. Identifiers and code remain identical in all languages.

| Action or route | HTTP method and request encoding |
| --- | --- |
| `upload` | POST; multipart/form-data |
| `inventory` | POST; application/json |
| `revoke` | POST; application/json \| form |
| `ai_next_job` | POST; application/json |
| `ai_heartbeat` | POST; application/json |
| `ai_complete` | POST; application/json |
| `ai_asset` | POST; form |
| `flight_report_status` | POST; application/json |
| `flight_report` | POST; multipart/form-data |
| `gallery_migration_manifest` | GET / POST; query / form |
| `gallery_migration_asset` | GET / POST; query / form |
| `gallery_migration_package` | POST; form |
| `gallery_migration_receive_manifest` | POST; form |
| `gallery_migration_receive_asset` | POST; multipart/form-data |
| `gallery_migration_receive_package` | POST; multipart/form-data |
| `gallery_migration_receive_status` | GET / POST; query / form |
| `gallery_migration_receive_complete` | POST; form |

<a id="connection"></a>

## Connection, keys and request conventions

In Admin, open the intended gallery, then its API tab. Generate a separately
labelled key for each client and copy both the exact upload endpoint and the
key. The raw key is displayed once; only its SHA-256 hash is stored. Ordinary
issued keys use `pgu_` followed by 64 hexadecimal characters. This API has no
refresh-token exchange or general expiry field. A key must remain active and not
revoked; it can also cease to work when its gallery or required storage is
unavailable. Rotate by creating and verifying a replacement for the same gallery
before revoking the old key. Do not publish credentials in source, screenshots,
URLs, logs or shared shell history.

Use the copied canonical front-controller URL, preserving any installation
subdirectory. The portable upload URL is
`<base>/index.php?page=upload_automation_upload`. `/api/upload` is a
rewrite-dependent compatibility alias, not the canonical address. Migration uses
the same `<base>/index.php?page=...` form with the exact route below. API keys
are not Admin sessions; key creation and global key listing require
authenticated Admin UI/CSRF and are not documented key-authenticated operations.

| Field | Type, requirement and meaning |
| --- | --- |
| `X-Gallery-API-Key` | Recommended header. First nonempty dedicated-header value wins after trimming. |
| `Authorization: Bearer` | Fallback when the dedicated header is empty. The bearer is still the original gallery key, not a linked access token. |
| `api_key` | Legacy scalar form field, used only if neither header supplied a usable credential. Not read from the query or a JSON object. |
| `Accept: application/json` | Request JSON refusals even for multipart calls. Binary success responses retain their actual media type. |
| `action` | For upload/automation, nonempty form value takes precedence over JSON; omitted/empty selects upload. Use only explicitly documented actions. |

Send exactly one credential form. An invalid higher-priority key is not rescued
by a valid lower-priority one. Keep TLS verification enabled and use HTTPS for
remote deployments; the legacy handler is not a substitute for HTTPS enforcement
at the hosting boundary. Refuse redirects while holding credentials, especially
changes of host or scheme. Do not reuse browser cookies or expose the key in
public JavaScript. Cross-origin browser CORS/preflight support is not promised
by this contract.

All code below uses synthetic URLs and shell variables. Set `API_URL` to the
copied endpoint and supply `PG_API_KEY` securely in the process environment; no
real key belongs in the examples. A curl transport exit code is not sufficient:
inspect the appended HTTP status and the JSON `ok` field. Do not use redirect
following or disable certificate verification.

```bash
API_URL='https://gallery.example/photos/index.php?page=upload_automation_upload'
: "${PG_API_KEY:?Set PG_API_KEY securely before running requests}"
# Never add -L or --insecure to credential-bearing calls.
```

<a id="upload"></a>

## Upload photographs

Operation: `POST index.php?page=upload_automation_upload`,
`multipart/form-data`, `action=upload` (also the default). Authentication and
`upload_api` must be available. Do not manually set a multipart boundary when
using curl. No Admin cookie or CSRF field is required for these
key-authenticated calls.

| Field | Type, requirement and meaning |
| --- | --- |
| `image / images[]` | Binary; one file or repeated files, required. Prefer one spelling per request; a nonempty images[] input wins. The server validates filenames and actual image bytes. |
| `gallery_id` | Optional integer assertion. A positive value different from the key gallery returns 403. Omitting it does not broaden authority. |
| `create_thumbnails` | Optional scalar flag; default true. True spellings: 1/true/yes/on, case-insensitive. Use 0 to suppress server derivative completion. Existing valid client derivatives may still be installed. |
| `image_client_ids[]` | Optional per-original correlation strings; [A-Za-z0-9_-], 1..80 characters. Aligned with original-file positions, not persistent operation IDs and not idempotency keys. |
| `client_thumbnails[]` | Optional binary derivative files. Each requires the corresponding three aligned thumbnail metadata arrays below. |
| `thumbnail_client_ids[]` | String; required for each thumbnail. Must match a valid image_client_ids value in this request. |
| `thumbnail_sizes[]` | Integer target long side in pixels; one of 300, 600, 800, 960, 1280, 1600. Actual decoded long side must not exceed the declaration by more than 4 pixels. |
| `thumbnail_formats[]` | String jpg or webp (normalized to lowercase); detected MIME must match. Valid extra JPG variants may be counted as skipped on a WebP-only installation, rather than rejecting the original. |
| `sim_location_source` | Optional string; only simconnect_camera enables the following shared camera coordinates. |
| `sim_camera_latitude / sim_camera_longitude` | Optional finite numbers, both required for accepted camera metadata. Latitude -90..90; longitude -180..180; rounded to 7 decimals. Invalid metadata is ignored, not a new GPS write endpoint. |
| `sim_camera_altitude` | Optional finite altitude in feet (legacy SimConnect unit; no conversion by this handler), rounded to 2 decimals; absent/invalid becomes null. One camera tuple applies to the accepted images of the request. |

Baseline image types are JPG/JPEG, PNG, GIF and WebP. HEIC/HEIF and DNG depend
on the actual server converters; do not infer support from a filename alone. No
PDF, music, arbitrary ZIP or JSON/base64 image upload belongs to this operation.
PHP upload_max_filesize, post_max_size, max_file_uploads and the hosting proxy
can impose tighter bounds. There is no advertised universal legacy batch size or
per-request rate budget. Use modest batches; do not copy limits from the
separate linked API.

The server natural-sorts uploaded filenames and may choose unique names or apply
configured renaming. Do not assume response positions preserve arbitrary input
order. When using position-based thumbnail correlation, submit originals already
in the same natural filename order; a single original with its derivatives
avoids ordering ambiguity. Re-uploading an original is not checksum-based
deduplication and can create another file. Read the returned filenames and image
IDs, not a guessed URL. Modern derivative policy is WebP-first; disabling
derivative creation does not guarantee previews already exist.

```bash
curl --silent --show-error --proto '=https' --max-time 120 \
  --header "X-Gallery-API-Key: $PG_API_KEY" \
  --header 'Accept: application/json' \
  --form 'action=upload' --form 'image=@photo.jpg' \
  --form 'create_thumbnails=1' \
  --write-out '\nHTTP %{http_code}\n' "$API_URL"
```

```json
{
  "ok": true, "gallery_id": 42, "gallery_title": "Example",
  "gallery_url": "https://gallery.example/photos/gallery/example",
  "edit_url": "/photos/index.php?page=admin_edit_gallery&id=42",
  "uploaded": 1, "scanned": 1, "image_ids": [501],
  "filenames": ["photo.jpg"], "renamed": 0,
  "rename_warnings": [], "rename_failures": [],
  "thumbnails": 6, "thumbnail_failed": 0, "thumbnail_errors": [],
  "post_upload_errors": [],
  "sim_camera_metadata": {"attached": 0, "skipped": 0, "error": ""},
  "client_thumbnails": {
    "installed": 0, "skipped": 0, "failed": 0, "errors": []
  }
}
```

The example is one possible response, not guaranteed counts or URL spelling.
`uploaded`, `scanned`, `renamed`, `thumbnails` and `thumbnail_failed` are
integer counters; `image_ids` is an integer list; `filenames`,
`rename_warnings`, `rename_failures`, `thumbnail_errors` and
`post_upload_errors` are string lists. Nested camera counts/error and
client-thumbnail counts/errors must also be inspected. HTTP 200 with `ok=true`
can coexist with failed derivative, GPS or bookkeeping work. It is not an atomic
all-or-nothing transaction. The upload catch maps failures to 422; shared
authentication failures are described below.

The following optional example attaches one prepared WebP derivative to one
original. The thumbnail must already have the declared dimensions and actual
WebP content; neither its filename nor its MIME declaration can bypass server
validation. Multiple originals can instead use repeated images[] fields, with
aligned image_client_ids[] in the server filename order.

```bash
curl --silent --show-error --proto '=https' --max-time 120 \
  --header "X-Gallery-API-Key: $PG_API_KEY" \
  --header 'Accept: application/json' \
  --form 'action=upload' --form 'images[]=@photo.jpg' \
  --form 'image_client_ids[]=photo-001' \
  --form 'client_thumbnails[]=@photo-300.webp;type=image/webp' \
  --form 'thumbnail_client_ids[]=photo-001' \
  --form 'thumbnail_sizes[]=300' --form 'thumbnail_formats[]=webp' \
  --form 'create_thumbnails=1' \
  --write-out '\nHTTP %{http_code}\n' "$API_URL"
```

<a id="inventory"></a>

## Inventory: compare supplied file candidates

Operation: `POST index.php?page=upload_automation_upload`, `application/json`,
`action=inventory`. It returns matches for supplied candidates in the one
authorized gallery, not a complete photo catalogue or a server-wide search. An
empty files list can check gallery identity and returned capabilities without
uploading anything. JSON calls must authenticate in a header.

| Field | Type, requirement and meaning |
| --- | --- |
| `action` | Required string inventory. |
| `files` | Array of candidate objects; omitted or non-array normalizes to empty. Invalid rows are discarded. Up to 5000 distinct SHA-256 values; duplicate hashes replace the earlier candidate. |
| `files[].sha256` | Required 64 hexadecimal characters; trimmed and lowercased. Hash the exact original bytes sent to the server. |
| `files[].filename` | Optional string; path components are removed. Empty becomes sha256. This is a display/correlation name, not a destination path. |
| `files[].size` | Optional integer bytes; normalized to at least zero. Positive size provides a secondary match check and is required for disk fallback. |
| `files[].client_id` | Optional trimmed string echoed for mapping; default sha256. It is not the image_client_ids upload identifier or a durable receipt. |
| `deep_check` | Optional JSON boolean, default false; only literal true activates it. Disk fallback considers at most the first 8 normalized candidates. This bounds candidate count, not the directory traversal time or number of directory entries inspected. |

```bash
python - <<'PYJSON' > inventory.json
import hashlib
import json
from pathlib import Path
path = Path("photo.jpg")
digest = hashlib.sha256(path.read_bytes()).hexdigest()
print(json.dumps({"action": "inventory", "deep_check": False,
    "files": [{"client_id": "photo-1", "filename": path.name,
               "size": path.stat().st_size, "sha256": digest}]}))
PYJSON
curl --silent --show-error --proto '=https' --max-time 120 \
  --header "X-Gallery-API-Key: $PG_API_KEY" \
  --header 'Accept: application/json' \
  --header 'Content-Type: application/json' \
  --data-binary @inventory.json \
  --write-out '\nHTTP %{http_code}\n' "$API_URL"
```

```json
{
  "ok": true, "action": "inventory", "gallery_id": 42,
  "gallery_title": "Example", "gallery_folder": "example",
  "checked": 1, "matched": 1,
  "existing": [{
    "client_id": "photo-1", "filename": "photo.jpg", "size": 1024,
    "sha256": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
    "remote_filename": "photo.jpg", "remote_relative_path": "photo.jpg",
    "remote_image_id": 501, "matched_by": "checksum_sha256"
  }],
  "deep_check_applied": false,
  "fingerprint": "opaque-server-fingerprint",
  "server_time": "2026-11-09 10:00:00",
  "capabilities": {"flight_reports_v1": true}
}
```

HTTP 200 returns `ok`, `action`, integer `gallery_id`, string `gallery_title`
and `gallery_folder`, integer `checked` and `matched`, an `existing` list,
boolean `deep_check_applied`, opaque `fingerprint`, SQL-style `server_time`, and
optional `capabilities`. Each match echoes client_id/filename/size/sha256 and
supplies remote_filename/remote_relative_path/remote_image_id/matched_by. Paths
are metadata, not public media URLs. `checksum_sha256` denotes an indexed match;
`disk_sha256` can have `remote_image_id=0`. No row means no observed match, not
proof that a previous request never committed. Keep fingerprints opaque;
SQL-style timestamps carry no explicit timezone offset.

<a id="recovery"></a>

## Partial results, timeouts and recovery

After a confirmed success, retain the receipt and inspect warnings. After a
timeout, disconnect, malformed response or truncated JSON, mark the upload
outcome unknown and retain the original file. Do not resend automatically,
delete local files, change the target, or assume that 422 implies rollback.
Probe inventory with the original bytes and, only when necessary, a small
explicit deep_check request. A positive match observes content, not
request-specific completion; a disk match does not prove database registration.
Reconcile the gallery and warnings before an operator deliberately decides
whether another upload is appropriate. This protocol exposes no general
photo-upload operation-status receipt, cancellation endpoint, universal
Idempotency-Key or exactly-once guarantee.

<a id="revoke"></a>

## Revoke the current key

Operation: POST to the upload endpoint, `action=revoke`, as JSON or URL-encoded
form. No token_id selector is accepted for this action: it revokes the
credential authenticating this request, for its gallery. The key cannot create a
replacement or revoke arbitrary other keys. Stop and reconcile pending work
first. This is an explicit destructive credential action; never run it as a
routine connection test. It does not delete photos. Subsequent use of the
revoked key receives 401 on the enabled upload route; replay is not a second
successful revocation receipt.

```bash
# OPTIONAL: this invalidates the currently supplied key.
curl --silent --show-error --proto '=https' --max-time 30 \
  --header "X-Gallery-API-Key: $PG_API_KEY" \
  --header 'Accept: application/json' \
  --data-urlencode 'action=revoke' \
  --write-out '\nHTTP %{http_code}\n' "$API_URL"
```

```json
{"ok": true, "action": "revoke", "gallery_id": 42,
 "token_id": 7, "message": "API key revoked."}
```

Success is HTTP 200. A failed revocation returns 422 with ok=false/error. Losing
the response requires checking current key usability or Admin key state; it does
not authorize a new key automatically.

<a id="ai-contract"></a>

## Specialized AI worker contract

The following four actions reuse the upload endpoint and gallery key, but are
specialized worker operations, not generic photo browsing. The installation must
enable upload_api and ai_image_metadata and have the AI queue/metadata schema.
The worker must hold the current job_id plus the secret claim_token as well as
the gallery key. Claims are scoped to that gallery; worker_id is diagnostic, not
authentication. Do not log claim tokens. No automatic browser, filesystem
crawler or perpetual polling loop is part of these examples.

Common AI responses: 403 if AI metadata is disabled; 409 if its schema is
missing; 503 if storage cannot be verified; 400 for an unknown ai_ action; 422
for other caught worker failures. The shared upload authentication/feature
refusals still precede these checks. A lost claim response is not permission to
complete another job: only a received claim token proves possession. Completion
retry semantics described below do not make photo uploads idempotent.

<a id="ai-next-job"></a>

## Claim one AI job: ai_next_job

POST, application/json, action=ai_next_job. This is a state-changing allocation:
missing jobs may be enqueued and one job claimed for direct images in the key
gallery. It is not a read-only connection probe.

| Field | Type, requirement and meaning |
| --- | --- |
| `worker_id` | Optional string; default unknown-worker; unsupported characters replaced with underscore, at most 190 characters. |
| `model_name / model_version` | Optional strings; defaults local-image-metadata / 1; whitespace collapsed, at most 120 characters each. Keep stable to identify the intended model/version queue. |
| `lease_seconds` | Optional integer seconds, default 600. Nonpositive values use default; otherwise clamped to 60..3600. Same normalization applies to heartbeat. |

```json
{"action":"ai_next_job","worker_id":"example-worker",
 "model_name":"local-image-metadata","model_version":"1",
 "lease_seconds":600}
```

```json
{
  "ok": true, "asset_action": "ai_asset",
  "job": {
    "job_id": 91, "claim_token": "REPLACE_WITH_RECEIVED_CLAIM_TOKEN",
    "lease_seconds": 600, "lease_expires_at": "2026-11-09 10:10:00",
    "model_name": "local-image-metadata", "model_version": "1",
    "gallery_id": 42, "gallery_title": "Example",
    "image": {
      "id": 501, "filename": "photo.jpg", "title": "Example",
      "relative_path": "photo.jpg", "mime_type": "image/jpeg",
      "file_size": 1024, "modified_at": "2026-11-09 09:00:00",
      "checksum_sha256": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
      "width": 1600, "height": 900
    }
  }
}
```

HTTP 200 returns a job object with the complete fields illustrated above, or
job=null with poll_after_seconds=60 and a message. Integer fields are job_id,
lease_seconds, gallery_id, `image.id`, `image.file_size`, `image.width`,
`image.height`; other shown job/image fields are strings. Respect the suggested
idle delay and the lease deadline. The placeholder is deliberately not a valid
claim token.

<a id="ai-heartbeat"></a>

## Renew an AI claim: ai_heartbeat

POST, application/json, action=ai_heartbeat. Extends the current live claim; it
cannot revive an expired or foreign claim.

| Field | Type, requirement and meaning |
| --- | --- |
| `job_id / claim_token` | Required positive integer and exact secret string from the claim response. The gallery key is still required in the header. |
| `lease_seconds` | Optional; same 600 default and 60..3600 second bounds as claim. |
| `progress_percent` | Optional integer; default 0, clamped to 0..99. Completion is a separate action. |
| `message` | Optional string; default Worker heartbeat.; stored text limited to 500 characters. |

```json
{"action":"ai_heartbeat","job_id":91,
 "claim_token":"REPLACE_WITH_RECEIVED_CLAIM_TOKEN",
 "lease_seconds":600,"progress_percent":40,"message":"Analyzing image"}
```

```json
{"ok":true,"job_id":91,"message":"Heartbeat accepted."}
```

HTTP 200 confirms renewal; 409 returns ok=false/error when the claim is invalid
or expired. Common AI errors also apply.

<a id="ai-complete"></a>

## Complete or fail an AI job: ai_complete

POST, application/json, action=ai_complete. Success stores model metadata and
searchable text; it is not an arbitrary photo-title/tag/settings mutation API.

| Field | Type, requirement and meaning |
| --- | --- |
| `job_id / claim_token` | Required claim identity, as for heartbeat. |
| `status` | Optional string; default succeeded. Send succeeded or failed explicitly. Current handler treats every other value as failure; do not rely on misspellings. |
| `metadata` | Success: JSON object, default empty. Worker-owned structured metadata, no fixed required tag vocabulary. Non-object/non-array values fail with 422. Do not store secrets or diagnostics as searchable content. |
| `searchable_text` | Success: optional string, default empty. Combined with scalar metadata text to depth 5, diagnostic keys excluded, whitespace normalized; final text limited to 60000 characters. |
| `error` | Failure: optional string, limited to 2000 characters. A server fallback message is used when omitted. Do not include credentials. |

```json
{"action":"ai_complete","job_id":91,
 "claim_token":"REPLACE_WITH_RECEIVED_CLAIM_TOKEN",
 "status":"succeeded","metadata":{"objects":["aircraft"]},
 "searchable_text":"Aircraft on the apron"}
```

```json
{"ok":true,"job_id":91,"status":"succeeded","message":"AI metadata stored."}
```

Failure acknowledgement is also HTTP 200 with status=failed and message="AI job
failure recorded."; it does not mean analysis succeeded. An invalid/expired
claim gives 409. Repeating a successful completion with the same
already-succeeded job/claim can acknowledge the stored result without replacing
it; this does not verify that a repeated payload is identical. A failure may be
requeued by server policy (maximum 5 attempts, exponential backoff capped at
3600 seconds). The client must not manufacture a new claim or blindly resubmit
after 409.

<a id="ai-asset"></a>

## Fetch the claimed AI input: ai_asset

POST to the upload endpoint using application/x-www-form-urlencoded (multipart
also populates the required form fields). Required fields are action=ai_asset,
positive integer job_id and the exact claim_token. Unlike the other AI actions,
this handler reads job_id and claim_token from form data, not JSON. Success is
HTTP 200 with image bytes, detected Content-Type, Content-Length,
Content-Disposition attachment, X-Content-Type-Options nosniff and Cache-Control
no-store, private. The server can return an existing display representation
rather than original RAW bytes; do not assume its hash equals the original
checksum in the job. It does not generate a missing derivative just to serve
this action.

```bash
# CLAIM_TOKEN and JOB_ID come from a successful ai_next_job response.
curl --silent --show-error --proto '=https' --max-time 120 \
  --header "X-Gallery-API-Key: $PG_API_KEY" \
  --header 'Accept: application/json' \
  --data-urlencode 'action=ai_asset' \
  --data-urlencode "job_id=$JOB_ID" \
  --data-urlencode "claim_token=$CLAIM_TOKEN" \
  --dump-header ai-asset.headers --output ai-asset.response \
  --write-out 'HTTP %{http_code}\n' "$API_URL"
```

An invalid claim or unavailable asset returns JSON 409 with ok=false/error;
other common AI failures remain JSON. Check HTTP status and Content-Type before
treating the saved response as an image.

<a id="flight-report-status"></a>

## Look up a PDF report receipt: flight_report_status

POST to the upload endpoint, application/json, action=flight_report_status. This
is the specialized report attachment protocol, not unrestricted PDF management.
The inventory response can advertise capabilities.flight_reports_v1=true; older
responses may omit capabilities. That advertisement is not a readiness
guarantee. On the audited revision it is unconditional after successful
inventory and does not independently verify PDF storage or the PDF presentation
switch. Do not infer other permissions or UI availability from it.

| Field | Type, requirement and meaning |
| --- | --- |
| `report_id` | Required string, 1..80 ASCII characters [A-Za-z0-9_-]. Stable caller identity reused for lookup and upload. |
| `language` | Required string en/cs/de/sv, normalized to lowercase. The Czech wire code is cs, not the manual filename suffix CZ. |
| `sha256` | Required 64-hex digest of the expected PDF; trimmed and lowercased. Identity is report_id + language within the key gallery. |

```json
{"action":"flight_report_status","report_id":"flight-demo-001",
 "language":"cs",
 "sha256":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"}
```

```json
{"ok":true,"action":"flight_report_status","found":false,
 "conflict":false,"report":null}
```

HTTP 200 has found boolean, conflict=false, and report=null when no verified
attachment is observed, otherwise report={id:string, sha256:string, size:integer
bytes, filename:string}. A matching receipt has found=true. The lookup is based
on verified indexed files; found=false is not proof that no lost write ever
happened. Conflicting content or duplicate identities return HTTP 409 with
ok=false, action, conflict=true and error. Invalid input or unverifiable status
returns 422; a missing/non-JSON status payload returns 400. No overwrite flag is
offered.

<a id="flight-report"></a>

## Attach a PDF report: flight_report

POST to the upload endpoint, multipart/form-data, action=flight_report. Required
scalar fields report_id, language and sha256 use the same rules as status. Also
send session_id (1..80 characters [A-Za-z0-9_-]) and one binary file in report.
The session_id is validated but is not part of the stored report identity. The
actual PDF must match the submitted SHA-256; valid files are 16 bytes through 25
MiB (26214400 bytes), with PDF signature, EOF marker and accepted detected PDF
MIME. Hosting limits may be lower.

```bash
PDF_SHA=$(python -c \
 'import hashlib; print(hashlib.sha256(open("report.pdf","rb").read()).hexdigest())')
curl --silent --show-error --proto '=https' --max-time 120 \
  --header "X-Gallery-API-Key: $PG_API_KEY" \
  --header 'Accept: application/json' \
  --form 'action=flight_report' --form 'report_id=flight-demo-001' \
  --form 'session_id=session-demo-001' --form 'language=cs' \
  --form "sha256=$PDF_SHA" --form 'report=@report.pdf;type=application/pdf' \
  --write-out '\nHTTP %{http_code}\n' "$API_URL"
```

```json
{"ok":true,"action":"flight_report","found":true,"conflict":false,
 "report":{"id":"0123456789abcdef0123456789abcdef",
 "sha256":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
 "size":2048,"filename":"flight-report-0123456789abcdef-cs.pdf"}}
```

HTTP 200 returns a stored or already-existing matching receipt. Resubmitting the
same report_id/language and identical PDF can return that receipt; another
checksum for the same identity returns 409 and is not overwritten.
Missing/incomplete PDF, validation/storage failure or checksum mismatch returns
422. After a lost response, query flight_report_status with the original
identity/checksum before any deliberate retry. This specific receipt behavior
must not be generalized to ordinary photo upload, inventory or arbitrary PDF
editing. The API does not expose report deletion, replacement or media browsing.

<a id="migration-contract"></a>

## Migration: authority and data contract

Migration is an explicit transfer of the gallery authorized by a source key into
a new child below the gallery authorized by a destination key. It is not server
discovery or a general browsing API. Each installation must enable
gallery_migration and have the required schema. The audited protocol_version is
2; source and destination app_version strings must match exactly. Preserve the
server-produced manifest, its identities, order and optional fields instead of
synthesizing a manifest from a local directory.

The source export includes descendants by default. Set include_subgalleries=0 to
request only the root. This parameter narrows that export request, not the
permanent authority of the key: a source asset request may authorize a
descendant in the same source tree. Treat a key as access to export data, not
merely upload permission. A target key authorizes creation of an imported
child/tree, not arbitrary root creation or mutation of unrelated galleries.
Imported metadata can retain source visibility; use a protected destination and
review the result before exposing it.

All migration URLs use index.php?page=<route>. GET fields belong in the query;
POST scalar fields use application/x-www-form-urlencoded or multipart/form-data.
JSON serialized inside manifest_json or assets_json is a form value, not an
application/json request body. On GET/POST routes the POST field wins when both
locations supply the same field. Always use a header credential for GET.
Successful JSON uses application/json; charset=utf-8. Error payloads have
ok=false and error:string, with action-specific status codes below. Wrong
methods return 405. Do not depend on localized error-message wording.

| Field | Type, requirement and meaning |
| --- | --- |
| `scope` | Asset selector, string image (default) or gallery. |
| `source_gallery_id` | Source gallery integer. Send the exact manifest value; source-side zero/omission falls back to the key root. Destination references must identify the manifest entry. |
| `kind` | Required string: image original or thumbnail; gallery cover_image_path, banner_image_path, logo_image_path or separator_image_path. |
| `source_image_id` | Integer for an image asset; historical alias image_id is accepted when source_image_id is absent. No image ID is needed for gallery artwork. |
| `size / format` | Thumbnail identity only: size integer pixels, format jpg or webp. Copy the descriptor, not a guessed size. size here is not byte length; byte length is file_size. |
| `file_size / checksum_sha256 / mime_type` | Descriptor metadata: byte count, SHA-256 digest and MIME string. Relay unchanged and verify downloaded bytes where a digest is supplied. |
| `filename / relative_path / asset_key / label` | Descriptor values, not arbitrary path access. asset_key is an opaque 24-hex identity added to transfer-plan entries; label is display text. Never construct filesystem URLs from paths. |

Migration transfers the assets enumerated by this protocol, not a complete
installation backup. General PDF-document sidecars and music
repositories/playlists are not automatically covered. Do not invent downloadable
URLs or promise transfer of unlisted hidden sidecars. Keep a separate
filesystem/database backup when preserving the entire installation.

<a id="migration-manifest"></a>

## Export the manifest

```text
GET|POST index.php?page=gallery_migration_manifest
```

Authenticate with the source gallery key. Optional gallery_id is a positive
integer assertion; a different root returns 403. include_subgalleries defaults
to true; the values 0, false, off and no (case-insensitive after trimming)
select root only. Success is HTTP 200 with ok, app_version, protocol_version and
manifest. Exceptions in authentication, schema lookup or manifest generation are
all mapped to 401 by this handler; 401 is therefore not exclusively a bad-key
diagnosis.

| Field | Type, requirement and meaning |
| --- | --- |
| `protocol_version / app_version` | Integer 2 and exact source application-version string. The destination requires the same application version. |
| `migration_id / source_instance_id` | Opaque strings generated by the source. Preserve both; the target uses source/manifest identity to derive a resumable job. |
| `source_gallery_id / source_gallery_folder` | Integer authorized root ID and its gallery-relative folder string, not an authorization token or direct media URL. |
| `include_subgalleries / generated_at / counts` | Boolean export selection, server SQL timestamp string and object {galleries:integer, images:integer, assets:integer}. |
| `galleries[]` | Parent-first entries: source_id, parent_source_id (root 0), source_folder, folder_name, relative_folder (root empty), gallery, gallery_assets[], images[] and flight_map (nullable). |
| `gallery / gallery_assets / images / flight_map` | Root mirrors retained for compatibility alongside galleries[]. Preserve them; do not merge different source snapshots. |

The nested gallery metadata carries only fields present in storage, plus tags
(string), description_layout_semantics_version, cover_source_id and optional
cover_selection_source_ids (integer array, including empty Automatic selection).
Optional content_language and translations depend on localization schema. Image
metadata includes source_id, source_gallery_id, tags and assets, plus present
persisted attributes listed below. Numeric database values can be strings, and
optional values can be null: do not coerce or translate these version-bound
transfer records. These are migration records, not general PATCH operations.
Pass the entire server-issued object unchanged.

```text
gallery:
title, slug, description, sort_order, visibility, voting_enabled,
show_filenames, newest_uploads_first, gallery_date, gallery_date_end,
description_layout, count_badge_visibility, picture_game_enabled,
gps_map_enabled, grid_columns, grid_rows, grid_use_for_subgalleries,
thumbnail_min_size, thumbnail_max_size, background_source,
banner_image_path, logo_image_path, separator_image_path,
cover_image_path, nsfw_enabled

images[]:
relative_path, filename, title, description, width, height, mime_type,
file_size, modified_at, checksum_sha256, sort_order, visibility,
exif_taken_at, exif_camera_make, exif_camera_model, exif_lens_model,
exif_focal_length, exif_aperture, exif_exposure_time, exif_iso,
gps_lat, gps_lng, gps_altitude, gps_extracted_at, nsfw_enabled,
thumbnail_min_size, thumbnail_max_size
```

```bash
SOURCE_BASE='https://source.example/photos'
: "${PG_SOURCE_KEY:?Set PG_SOURCE_KEY securely}"
curl --silent --show-error --proto '=https' --max-time 120 \
  --header "X-Gallery-API-Key: $PG_SOURCE_KEY" \
  --header 'Accept: application/json' \
  --get --data-urlencode 'include_subgalleries=0' \
  "$SOURCE_BASE/index.php?page=gallery_migration_manifest" \
  --output manifest-response.json --write-out 'HTTP %{http_code}\n'
# Validate HTTP/ok before using the file. Extract only the manifest member.
python -c 'import json; r=json.load(open("manifest-response.json")); assert r["ok"] is True; json.dump(r["manifest"],open("manifest.json","w"))'
```

When present, flight_map is an object with map_source_type:string,
route_text:string, resolved_points_json:string, unresolved_points_json:string,
point_count:integer and resolved_at:string. The two *_json fields contain
serialized JSON strings, not nested arrays. Keep them unchanged with the
version-bound manifest; no separate route creation API is implied.

<a id="migration-asset"></a>

## Download one source asset

```text
GET|POST index.php?page=gallery_migration_asset
```

Use the source key and the asset selectors defined above. The server resolves
the selected original, existing thumbnail or gallery artwork within the
authorized root/tree. A source path supplied by the caller cannot choose an
arbitrary file. No manifest job_id is required on this source route. Descendant
authorization is checked for each request, independently of whether the
preceding manifest excluded descendants. Success is HTTP 200 binary with
detected Content-Type, Content-Length and attachment Content-Disposition.
Exceptions, including authentication or missing files, map to 404 JSON; wrong
method is 405. Do not save a JSON error as a photograph.

```bash
curl --silent --show-error --proto '=https' --max-time 120 \
  --header "X-Gallery-API-Key: $PG_SOURCE_KEY" \
  --header 'Accept: application/json' \
  --get --data-urlencode 'scope=image' \
  --data-urlencode 'source_gallery_id=12' \
  --data-urlencode 'source_image_id=34' --data-urlencode 'kind=original' \
  "$SOURCE_BASE/index.php?page=gallery_migration_asset" \
  --output source-asset.bin --write-out 'HTTP %{http_code}\n'
# IDs must come from the actual authorized manifest, not this example.
```

<a id="migration-package"></a>

## Download a source ZIP package

```text
POST index.php?page=gallery_migration_package
```

Source key, form fields: assets_json (required JSON-encoded nonempty descriptor
array, at most 2048 assets) and include_subgalleries (optional, true by
default). Obtain the exact asset group from the destination package plan. The
source resolves each descriptor, verifies available size/checksum evidence and
emits a ZIP using stored entries (ZIP compression method 0). ZipArchive support
is required. Success: HTTP 200 application/zip, Content-Length, attachment
filename php-gallery-migration-package.zip. Exceptions including invalid
credentials, descriptor/size/hash problems or ZIP failure return 422 JSON. Wrong
method returns 405.

```bash
# package-assets.json is one packages[i].assets array from the target plan.
curl --silent --show-error --proto '=https' --max-time 120 \
  --header "X-Gallery-API-Key: $PG_SOURCE_KEY" \
  --header 'Accept: application/json' \
  --data-urlencode 'assets_json@package-assets.json' \
  --data-urlencode 'include_subgalleries=0' \
  "$SOURCE_BASE/index.php?page=gallery_migration_package" \
  --output transfer.zip --write-out 'HTTP %{http_code}\n'
```

<a id="migration-receive-manifest"></a>

## Prepare the destination job

```text
POST index.php?page=gallery_migration_receive_manifest
```

Destination gallery key, required form field manifest_json containing only the
full manifest object from the source response. This is a mutation: it prepares a
server job and the imported child gallery/tree below the key gallery. Reuse the
exact manifest and destination to recover a compatible existing job; do not
obtain a changed snapshot or choose a new target after an ambiguous response.
The server-generated job_id is opaque; persist it with source/destination
identity and manifest, but without secrets. Application versions must match
exactly and protocol_version must be supported. Exceptions (authentication
included) return 422 with ok=false/error; wrong method returns 405.

| Field | Type, requirement and meaning |
| --- | --- |
| `ok / app_version / job_id` | HTTP 200: true, destination version string and job identity string. |
| `compatibility` | Object {ok:boolean, source_version:string, target_version:string, policy:"exact", message:string}. Incompatible requests fail before successful preparation. |
| `target_gallery_id / imported_root_gallery_id / gallery_ids` | Authorized parent integer; created/imported root integer; array of imported gallery integers. Parent and imported root are distinct concepts. |
| `assets[] / packages[]` | Ordered asset descriptors with asset_key/label; package objects {package_id:string, asset_keys:string[], assets:object[], asset_count:integer, source_bytes:integer}. Use the returned plan, not independently regrouped ZIPs. |
| `counts / status` | Counts object {galleries:integer, images:integer, assets:integer, packages:integer, received:integer}. status is the job-status object described below (nullable in the controller envelope). |

Package planning respects destination upload limits and a soft batch target. One
original plus its derivatives can exceed the soft target while staying within
the hard limit; a group above the hard limit is rejected. Do not advertise a
universal 24 MiB upload maximum merely because that is a fallback planning
target. The plan may contain no assets for an empty gallery, which still needs
completion.

```bash
TARGET_BASE='https://target.example/gallery'
: "${PG_TARGET_KEY:?Set PG_TARGET_KEY securely}"
curl --silent --show-error --proto '=https' --max-time 120 \
  --header "X-Gallery-API-Key: $PG_TARGET_KEY" \
  --header 'Accept: application/json' \
  --data-urlencode 'manifest_json@manifest.json' \
  "$TARGET_BASE/index.php?page=gallery_migration_receive_manifest" \
  --output job-response.json --write-out 'HTTP %{http_code}\n'
# Preserve the validated job response before transferring any bytes.
```

<a id="migration-receive-asset"></a>

## Upload one asset to the destination

```text
POST index.php?page=gallery_migration_receive_asset
```

Destination key and multipart/form-data. Required: job_id, the asset selector
fields and a binary file in asset. Selectors must identify a planned manifest
asset; the server checks job ownership, descriptor, content size/hash and
destination safety. Transfer the returned asset order so originals precede their
thumbnails. Gallery artwork requires scope=gallery and its exact kind. Success
is HTTP 200 with ok=true, job_id, asset_key, target_gallery_id (the imported
gallery receiving this asset), result, received and total_assets. This
target_gallery_id can differ from the key parent. Exceptions including invalid
credentials, unknown job/asset, missing upload or checksum mismatch return 422;
wrong method 405.

| Field | Type, requirement and meaning |
| --- | --- |
| `result: image original` | {scope:"image", kind:"original", source_image_id:integer, image_id:integer}; source and destination IDs differ. |
| `result: image thumbnail` | {scope:"image", kind:"thumbnail", source_image_id:integer, image_id:integer, size:integer, format:string}. |
| `result: gallery` | {scope:"gallery", kind:string, relative_path:string}; kind is the artwork column from the descriptor. |

```json
{"ok":true,"job_id":"0123456789abcdef0123456789abcdef",
 "asset_key":"0123456789abcdef01234567","target_gallery_id":41,
 "result":{"scope":"image","kind":"original",
 "source_image_id":34,"image_id":100},"received":1,"total_assets":3}
```

<a id="migration-receive-package"></a>

## Upload a planned ZIP package

```text
POST index.php?page=gallery_migration_receive_package
```

Destination key, multipart/form-data: job_id and package_id are required strings
from preparation; package is the required binary ZIP. No assets_json is accepted
as a replacement destination plan. Send the unmodified ZIP generated by the
source for that exact package. The receiver checks expected entry names/count,
rejects duplicate/unexpected entries and compressed entries (only stored method
0), verifies asset evidence and uses its authorized job mapping. Some assets can
be installed before a later failure; this is not an atomic rollback protocol.
After an ambiguous upload, call receive_status for the same job before deciding
to resend.

```json
{"ok":true,"job_id":"0123456789abcdef0123456789abcdef",
 "package_id":"abcdef0123456789abcdef01",
 "asset_keys":["0123456789abcdef01234567"],
 "received":1,"total_assets":1}
```

HTTP 200 fields above contain strings/string arrays and integer counts.
Authentication, job/plan mismatch, invalid ZIP, missing package, wrong content
or incomplete transfer exceptions return 422 JSON. Wrong method returns 405. A
package already recorded as received is not permission to switch its content or
move it to a different job. Use current status and original package identity for
recovery.

<a id="migration-receive-status"></a>

## Reconcile destination progress

```text
GET|POST index.php?page=gallery_migration_receive_status
```

Destination key and required job_id. Optionally include the six asset-selector
fields to ask about one manifest asset. The same target-parent ownership is
checked on every call. This handler reconciles stored receipt evidence against
files; even GET can update recovery bookkeeping, so do not describe it as a
side-effect-free snapshot. Missing/unmatched optional asset selection produces
asset_key="" and asset_received=false rather than a global file-absence
guarantee. Invalid key/job/target, corrupt job or reconciliation exceptions
return 422; wrong method 405.

```json
{"ok":true,"action":"status",
 "job_id":"0123456789abcdef0123456789abcdef","mode":"source_push",
 "target_gallery_id":40,"imported_root_gallery_id":41,"gallery_ids":[41],
 "received":1,"total_assets":3,"received_packages":0,"total_packages":1,
 "received_asset_keys":["0123456789abcdef01234567"],
 "asset_key":"","asset_received":false,
 "updated_at":"2026-11-09 12:00:00","server_time":"2026-11-09 12:00:03"}
```

The complete HTTP 200 shape is shown above. IDs/counts are integers, gallery_ids
is an integer array, received_asset_keys is a string array, times are server SQL
strings, and asset_received is boolean. received_packages counts packages for
which every constituent asset is received. mode is source_push for jobs started
through this public preparation route. There is no completed flag in this
response. received==total_assets means bytes are accounted for, not that final
metadata application succeeded; call receive_complete separately.

<a id="migration-receive-complete"></a>

## Complete the destination job

```text
POST index.php?page=gallery_migration_receive_complete
```

Destination key and required form field job_id, no file. The server requires the
authorized target job, all expected assets and usable schema, then applies
metadata, source-to-target image/cover mappings and related finalization. This
can change the imported gallery presentation/visibility. A lost success response
is not proof of rollback. Preserve the original job and inspect its result; do
not start another import merely because completion was ambiguous. No public
cancel/delete-job or rollback endpoint is defined by this contract.

```json
{"ok":true,"job_id":"0123456789abcdef0123456789abcdef",
 "target_gallery_id":40,"target_parent_gallery_id":40,
 "imported_root_gallery_id":41,"gallery_ids":[41],
 "assets_received":3,"total_assets":3,
 "gallery_url":"https://target.example/gallery/gallery/imported/",
 "edit_url":"https://target.example/gallery/index.php?page=admin_edit_gallery&id=41&tab=admin-edit-api#admin-edit-api"}
```

HTTP 200 contains the fields above; IDs/counts are integers, gallery_ids is an
integer array and URLs are strings (use the returned values, not this
illustrative spelling). Authentication, incomplete assets,
target/schema/metadata failures return 422 with ok=false/error, wrong method
405. The Admin edit_url is a browser link requiring its normal Admin
authentication; it does not grant API-key access to Admin functions.

<a id="errors"></a>

## HTTP errors, limits and compatibility

The following matrix describes handler behavior on the reviewed baseline, after
the request reaches PHP. It is not a uniform REST status policy.
Disabled-feature routing, invalid installation/schema state, PHP body limits, a
reverse proxy or an upstream error can produce another status or HTML before the
handler. Send Accept: application/json; validate status, Content-Type and JSON
shape independently. Do not infer write rollback from any HTTP error or failed
JSON parse. Do not branch on translated free-form error messages.

| Operation group | Failure interpretation |
| --- | --- |
| `All upload actions` | 405 wrong method; 503 token schema unavailable/unverifiable; 401 missing/invalid/revoked key; 404 key gallery missing. These occur before action dispatch. |
| `upload / inventory / revoke` | Upload: 403 positive gallery assertion mismatch, 422 caught upload failure. Revoke: 422 failed revocation. Inventory has no dedicated local exception-to-status mapping; infrastructure exceptions need not produce the usual JSON envelope. |
| `ai_*` | 403 disabled AI feature, 409 missing queue schema, 503 unverifiable schema, 400 unknown AI action, 422 caught AI failure; heartbeat/complete/asset 409 for an invalid claim or unavailable claimed input. |
| `flight_report_status / flight_report` | Status: 400 missing JSON body; both: 409 conflicting PDF identity, 422 invalid input or status/storage failure. |
| `gallery_migration_manifest` | 403 root assertion mismatch; 401 any caught exception including invalid key, unavailable schema or export failure; 405 wrong method. |
| `gallery_migration_asset` | 404 caught authentication/resolution/read preparation failure; 405 wrong method. A failure after streaming begins need not be recoverable as clean JSON. |
| `gallery_migration_package / gallery_migration_receive_*` | 422 any caught exception including key/target/job/version/payload/ZIP/integrity problems; 405 wrong method. Authentication errors are not universally 401 on these routes. |

```json
{"ok":false,"error":"Invalid or revoked API key."}
```

```json
{"ok":false,"action":"flight_report_status","conflict":true,
 "error":"A different PDF is already stored for this report identity."}
```

These are separate error examples, not a universal shape requiring action or
conflict. For a key-only caller without Admin cookies, a disabled upload_api or
gallery_migration route normally returns 404 with
{"ok":false,"error":"not_found"} when JSON is requested; an authenticated Admin
context can instead get 403 feature_disabled. Keep API calls independent of
browser sessions. The two JSON helpers are not identical: upload uses
application/json, migration explicitly adds charset=utf-8. Do not assume all
success/error replies set identical cache/security headers. Treat all
authenticated metadata as private and never cache credentials.

Operational limits are the intersection of application validation, installed
feature/schema support, PHP upload_max_filesize/post_max_size/max_file_uploads,
web-server body/time limits and client memory/time limits. A host can return 413
or 429 without those being uniform application-level guarantees. No universal
Retry-After, pagination contract, resumable multipart upload, chunk API, rate
quota or exactly-once guarantee is defined here. Retain all original files and
uncertain-operation evidence. Inventory and report receipts have different
meanings. Respect optional/missing response fields on older servers; no general
API-version endpoint is promised. Migration alone requires exact
application-version equality.

<a id="complete-client"></a>

## Complete Python upload, inventory and migration example

Save the following complete script as gallery_api_example.py. It needs Python
3.10+ and no third-party package. PG_BASE, PG_SOURCE_BASE and PG_TARGET_BASE are
explicit installation-base URLs including any subdirectory, without index.php,
query or fragment. Supply keys in environment variables only. The example
deliberately bounds each file/binary response to 64 MiB and JSON to 8 MiB, with
a 120-second socket timeout; these are client-example limits, not server limits.
It buffers bounded files and uses ASCII multipart filenames; larger or
differently named uploads require an appropriately streaming/encoding-aware
client. urllib may use the configured operator proxy. TLS verification stays
enabled and every redirect is refused.

The migrate command performs a complete new manifest -> destination preparation
-> ordered per-asset transfer -> completion sequence for the explicitly selected
source/target keys. It uses individual-asset routes so a ZIP tool is
unnecessary; the package routes above provide a separate batching option. It
refuses an existing work directory rather than accidentally starting a second
import. Private manifest/job files survive an error; they may contain private
gallery metadata, but never credentials. After an error, inspect the saved job
and query its status manually. Do not simply rerun into a different directory.
Missing job-response.json after preparation failure still requires reconciling
the original saved manifest and target before another attempt. The script never
removes source photographs or automatically retries writes. Printed upload JSON
can contain warnings even when ok=true.

```python
"""Example client for the documented gallery-key API. Author: Rudolf Klusal.

Requires Python 3.10+ and the standard library. It never follows redirects,
retries writes, deletes original files, or enumerates unrelated galleries.
The bounds below belong to this example, not to PHP Gallery's API contract.
"""

import argparse
import hashlib
import json
import os
from pathlib import Path
import secrets
import ssl
import sys
import urllib.error
import urllib.parse
import urllib.request

# Example-only memory/time bounds; stop explicitly when an input is larger.
FILE_LIMIT = 64 * 1024 * 1024
JSON_LIMIT = 8 * 1024 * 1024
TIMEOUT_SECONDS = 120
UPLOAD_ROUTE = "upload_automation_upload"
ASSET_FIELDS = (
    "scope", "source_gallery_id", "kind", "source_image_id", "size", "format"
)


class NoRedirect(urllib.request.HTTPRedirectHandler):
    """Refuse all redirect responses so credentials never follow a new URL."""

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        """Return no replacement request; urllib reports an HTTP error."""
        return None


class Api:
    """Call fixed gallery routes with one installation-specific key."""

    def __init__(self, base: str, key: str) -> None:
        """Validate the explicit HTTPS installation base and retain its key."""
        parsed = urllib.parse.urlsplit(base)
        if (parsed.scheme != "https" or not parsed.hostname
                or parsed.username or parsed.password
                or parsed.query or parsed.fragment or not key):
            raise ValueError("Use an HTTPS installation base and a nonempty key")
        self.base = base.rstrip("/")
        self.key = key
        self.opener = urllib.request.build_opener(
            NoRedirect(), urllib.request.HTTPSHandler(
                context=ssl.create_default_context()
            )
        )

    def call(self, route: str, fields: dict | None = None,
             payload: dict | None = None,
             upload: tuple[str, str, bytes] | None = None,
             binary: bool = False) -> dict | bytes:
        """POST once; return verified JSON success or binary bytes, else raise.

        :param route: Fixed documented front-controller route.
        :param fields: Scalar form fields, not JSON request-body fields.
        :param payload: JSON body; mutually exclusive with fields/upload.
        :param upload: Multipart (field name, ASCII filename, file bytes).
        :param binary: Read a bounded binary attachment instead of JSON.
        :return: Successful JSON object or received binary bytes.
        :raises RuntimeError: HTTP, transport, body-limit or JSON failure.
        """
        if payload is not None and (fields is not None or upload is not None):
            raise ValueError("JSON and form/multipart cannot be combined")
        headers = {"X-Gallery-API-Key": self.key, "Accept": "application/json"}
        if upload is not None:
            field, filename, content = upload
            if len(content) > FILE_LIMIT:
                raise ValueError("File exceeds the example's memory bound")
            filename.encode("ascii")
            if any(c in field + filename for c in '\r\n"\\'):
                raise ValueError("Unsafe multipart field/filename")
            boundary = "pg-example-" + secrets.token_hex(16)
            chunks = []
            for name, value in (fields or {}).items():
                chunks.append((f"--{boundary}\r\nContent-Disposition: "
                               f'form-data; name="{name}"\r\n\r\n'
                               f"{value}\r\n").encode("utf-8"))
            chunks.extend([
                (f"--{boundary}\r\nContent-Disposition: form-data; "
                 f'name="{field}"; filename="{filename}"\r\n'
                 "Content-Type: application/octet-stream\r\n\r\n").encode(),
                content, f"\r\n--{boundary}--\r\n".encode(),
            ])
            data = b"".join(chunks)
            headers["Content-Type"] = f"multipart/form-data; boundary={boundary}"
        elif payload is not None:
            data = json.dumps(payload).encode("utf-8")
            headers["Content-Type"] = "application/json"
        else:
            data = urllib.parse.urlencode(fields or {}).encode("ascii")
            headers["Content-Type"] = "application/x-www-form-urlencoded"
        url = self.base + "/index.php?" + urllib.parse.urlencode({"page": route})
        request = urllib.request.Request(url, data=data, headers=headers,
                                         method="POST")
        try:
            with self.opener.open(request, timeout=TIMEOUT_SECONDS) as response:
                limit = FILE_LIMIT if binary else JSON_LIMIT
                body = response.read(limit + 1)
                mime = response.headers.get_content_type()
                if response.status != 200 or len(body) > limit:
                    raise RuntimeError("Unexpected status or oversized response")
        except urllib.error.HTTPError as exc:
            status = exc.code
            exc.close()
            raise RuntimeError(f"HTTP {status}; no automatic retry") from None
        except (urllib.error.URLError, TimeoutError, OSError) as exc:
            raise RuntimeError("Transport failed; write outcome may be unknown") from exc
        if binary:
            if mime in ("application/json", "text/html"):
                raise RuntimeError("Expected attachment, received JSON/HTML")
            return body
        if mime != "application/json":
            raise RuntimeError("Expected JSON, received another media type")
        result = json.loads(body)
        if not isinstance(result, dict) or result.get("ok") is not True:
            raise RuntimeError("Server did not confirm ok=true; inspect safely")
        return result


def read_file(path: Path) -> bytes:
    """Read one bounded original without modifying it; raise if too large."""
    with path.open("rb") as stream:
        content = stream.read(FILE_LIMIT + 1)
    if len(content) > FILE_LIMIT:
        raise ValueError("File exceeds the example's 64 MiB limit")
    return content


def save_json(path: Path, value: dict) -> None:
    """Exclusively save non-secret transfer metadata in the private workdir."""
    with path.open("x", encoding="utf-8") as stream:
        json.dump(value, stream, ensure_ascii=False, indent=2)
        stream.write("\n")


def migrate(directory: Path, include_children: bool) -> dict:
    """Run a new explicit migration; preserve evidence and stop on any failure.

    :param directory: New private directory; an existing directory is refused.
    :param include_children: Explicitly include the authorized source tree.
    :return: Successful completion receipt. Never retries ambiguous writes.
    """
    source = Api(os.environ["PG_SOURCE_BASE"], os.environ["PG_SOURCE_KEY"])
    target = Api(os.environ["PG_TARGET_BASE"], os.environ["PG_TARGET_KEY"])
    directory.mkdir(mode=0o700, parents=False, exist_ok=False)
    envelope = source.call("gallery_migration_manifest", {
        "include_subgalleries": "1" if include_children else "0"
    })
    save_json(directory / "source-response.json", envelope)
    manifest = envelope["manifest"]
    # This source snapshot is retained BEFORE any destination mutation.
    save_json(directory / "manifest.json", manifest)
    prepared = target.call("gallery_migration_receive_manifest", {
        "manifest_json": json.dumps(manifest)
    })
    save_json(directory / "job-response.json", prepared)
    job_id = prepared["job_id"]
    status = target.call("gallery_migration_receive_status", {"job_id": job_id})
    received = set(status["received_asset_keys"])
    for asset in prepared["assets"]:
        if asset["asset_key"] in received:
            continue
        selectors = {k: asset[k] for k in ASSET_FIELDS if k in asset}
        content = source.call("gallery_migration_asset", selectors, binary=True)
        expected_size = int(asset.get("file_size") or 0)
        expected_hash = asset.get("checksum_sha256") or ""
        if expected_size > 0 and len(content) != expected_size:
            raise RuntimeError("Source size changed; stop and retain the job")
        if expected_hash and hashlib.sha256(content).hexdigest() != expected_hash:
            raise RuntimeError("Source checksum changed; stop and retain the job")
        target.call("gallery_migration_receive_asset",
                    {"job_id": job_id, **selectors},
                    upload=("asset", "asset.bin", content))
    result = target.call("gallery_migration_receive_complete", {"job_id": job_id})
    save_json(directory / "completion.json", result)
    return result


def main() -> None:
    """Dispatch one explicit upload/inventory or a new authorized migration."""
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("operation", choices=("upload", "inventory", "migrate"))
    parser.add_argument("path", type=Path)
    parser.add_argument("--include-children", action="store_true")
    args = parser.parse_args()
    if args.operation == "migrate":
        result = migrate(args.path, args.include_children)
    else:
        client = Api(os.environ["PG_BASE"], os.environ["PG_API_KEY"])
        content = read_file(args.path)
        filename = args.path.name
        if args.operation == "upload":
            result = client.call(UPLOAD_ROUTE, {"action": "upload"},
                                 upload=("image", filename, content))
        else:
            result = client.call(UPLOAD_ROUTE, payload={
                "action": "inventory", "files": [{
                    "client_id": "example-1", "filename": filename,
                    "size": len(content),
                    "sha256": hashlib.sha256(content).hexdigest(),
                }]
            })
    print(json.dumps(result, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    try:
        main()
    except (KeyError, ValueError, OSError, RuntimeError) as error:
        detail = str(error) if isinstance(error, RuntimeError) else type(error).__name__
        print(f"Stopped: {detail}. Preserve inputs and inspect "
              "the original gallery/job; do not blindly repeat writes.",
              file=sys.stderr)
        sys.exit(1)
```

```bash
export PG_BASE='https://gallery.example/photos'
: "${PG_API_KEY:?Set securely}"
export PG_API_KEY
python gallery_api_example.py inventory photo.jpg
# The next command stores a photograph. Do not blindly repeat it.
python gallery_api_example.py upload photo.jpg

export PG_SOURCE_BASE='https://source.example/photos'
export PG_TARGET_BASE='https://target.example/gallery'
: "${PG_SOURCE_KEY:?Set securely}" "${PG_TARGET_KEY:?Set securely}"
# Keys must be exported into the child process environment.
export PG_API_KEY PG_SOURCE_KEY PG_TARGET_KEY
# Creates a child in the target; the workdir must not exist.
python gallery_api_example.py migrate ./migration-evidence
# Add --include-children only for an explicitly authorized subtree transfer.
```

For a retained job, the following status request does not create a new import.
JOB_ID is copied from the validated job-response.json. Decide how to recover
from the returned evidence; absence of a completion flag is intentional. A
finalization request is a separate deliberate mutation, not a harmless
connectivity test.

```bash
curl --silent --show-error --proto '=https' --max-time 120 \
  --header "X-Gallery-API-Key: $PG_TARGET_KEY" \
  --header 'Accept: application/json' --data-urlencode "job_id=$JOB_ID" \
  "$TARGET_BASE/index.php?page=gallery_migration_receive_status"
# Only after reviewing the original job and its complete asset receipts:
curl --silent --show-error --proto '=https' --max-time 120 \
  --header "X-Gallery-API-Key: $PG_TARGET_KEY" \
  --header 'Accept: application/json' --data-urlencode "job_id=$JOB_ID" \
  "$TARGET_BASE/index.php?page=gallery_migration_receive_complete"
```

<a id="source-map"></a>

## Implementation map and maintenance

The public contract above is intentionally separate from internal function
documentation. The following source owners support verification of types, gates
and error behavior. Preserve comments/docstrings and review Markdown plus all
four manual editions when changing these wire contracts. A branch-only
implementation is not automatically a supported released API. Do not add
automatic browsing, server-wide listing or browser-approved APIs merely because
their routes exist. docs/API.MD is the English reference; the corresponding
manual chapters contain the same technical content in the edition language.

```text
app/bootstrap.php
app/bootstrap/routing.php
app/bootstrap/dispatch.php
app/controllers/upload_automation.php
app/services/upload_automation.php
app/models/upload_automation.php
app/views/upload_automation.php
app/services/ai_image_analysis.php
app/services/upload_flight_reports.php
app/services/gallery_pdf_documents.php
app/controllers/gallery_migration.php
app/services/gallery_migration/versions.php
app/services/gallery_migration/manifest.php
app/services/gallery_migration/metadata.php
app/services/gallery_migration/assets.php
app/services/gallery_migration/target_setup.php
app/services/gallery_migration/install.php
app/services/gallery_migration/packages.php
app/services/gallery_migration/jobs.php
app/services/gallery_migration/recovery.php
winapp/uploader/simconnect_location.py
winapp/uploader/flight_delivery.py
docs/HTTP_ENTRYPOINTS.md
docs/COMPATIBILITY_LIFECYCLE.md
docs/AGENTS.md
docs/LATEX_BUILD.md
```

Related documentation: [HTTP entrypoints](HTTP_ENTRYPOINTS.md), [compatibility](COMPATIBILITY_LIFECYCLE.md), [manual build](LATEX_BUILD.md), [project README](../README.md).
