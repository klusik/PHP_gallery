<!--
Project: PHP Gallery
Repository: https://github.com/klusik/PHP_gallery
File: docs/MOBILE_WEBDAV_BODY.md
Module Type: Architecture Guide
Purpose: Explain temporary WebDAV stream ownership and schema-refusal preservation.
Responsibilities: Record ownership, safe cleanup and verification limits.
Author: Rudolf Klusal
-->

# WebDAV request-body ownership

The PUT controller owns request normalization, credential authentication, the
existing ingestion-schema preflight, opening `php://input`, response mapping and
closing that stream. `mobile_webdav_store_put_stream()` in the existing
`app/services/mobile_webdav.php` owns temporary-file allocation, bounded-memory
stream copying, output closure and request-body cleanup. Its arguments are the
authenticated token identity/destination, target resource path and open input
resource; it does not inspect request globals or accept a cleanup pathname.

Installation still delegates to `mobile_webdav_store_put()`: its existing
reentrant global writer guard, fresh destination lookup, credential/ingestion
preflights, filename policy, original move/copy and scanner remain unchanged.
Staging does not mutate a gallery and occurs before that writer lease is taken.
The caller-owned source-path API remains available and keeps its existing
consume-on-install contract; the stream facade does not authorize cleanup of
files supplied to that separate API.

## Cleanup and recoverability

Only the file freshly allocated for this invocation is eligible for cleanup.
The service checks its canonical temporary directory, expected native prefix,
regular-file/non-symlink status and file identity. It checks identity again before
deleting an ordinary failed staging/validation attempt. A rejected or unverifiable
path does not authorize deletion. The native prefix remains `pg-webdav-`; Windows
`tempnam()` keeps only its first three characters. These checks do not replace the
host's isolation and permissions on its temporary directory.

If a later credential or ingestion schema check refuses the operation, both
`missing` and `unknown` retain the staged file instead of deleting the recoverable
body. The exception is rethrown unchanged. Previously retained files are never
searched for, retried, expired or purged by another request. This follows the
[Phase 10 preflight/recoverability rules](../ARCHITECTURE.md#phase-10-destructive-and-ingestion-mutation-policy).

This is retention in the existing OS temporary location, **not a durable job or
retry protocol**. Hosting cleanup can still remove the file. No path, token,
password or body is added to responses/logs; no recovery metadata or destination
binding is persisted. Operators needing recovery must protect the relevant host
temp storage, resolve the schema problem, and independently verify the intended
destination and current gallery state before arranging an intentional upload from
the original sender or securely recovered bytes. Do not blindly retry all matching
temp files or assume WebDAV has the create/classic-upload replay ledger.

Retention preserves a body only while it is still staged. If the existing installer
has already consumed it and a later scan fails, this facade neither rolls back the
installed original nor reconstructs temporary input. Extending that boundary to a
durable resumable workflow, adding a recovery index, or changing retention/expiry
policy requires a separate design decision. This tranche does not change schema
requirements or their existing installation order.

## Responses and resource limits

Existing response mapping is retained:

| Outcome | HTTP response |
| --- | --- |
| Authentication fails | 401 before body opening/allocation |
| Initial ingestion preflight missing / unknown | 409 / 503 before body opening/allocation |
| Input opening, allocation, output opening, copy or flush fails | 500 with existing safe translated body error |
| Successful guarded installation | 201 |
| Late schema unknown / ordinary installation refusal (including late missing) | 503 / 422 |

Copying uses native stream-to-stream transfer instead of loading the entire body
into a PHP string. Partial reads, copy failure and flush failure stop before
installation. The service never closes its caller's input; the controller closes
it on success and failure. This change introduces no new application byte quota,
timeout or background cleanup. Existing staging prefixes now have documented
immutable definitions in Core policy; their values are unchanged. Existing server/request/disk
limits still apply; bounded-memory copying is not a disk-exhaustion guarantee.

## Isolated regression evidence

`tests/mobile_webdav_body_test.php` executes the real controller, authentication,
three-state policy, stream facade and guarded installation using a one-pixel PNG,
memory input streams, unique disposable files and fake persistence/scanning.
It covers move and copy success, stream ownership, allocation/read/partial-copy/
flush faults, rejected unowned paths, unauthorized and early-schema refusals,
late credential/ingestion missing/unknown body retention, safe responses, ordinary
failure cleanup, and preservation of earlier retained inputs.

The test needs ordinary PHP streams, hashing/password support and image-header
inspection; it does not decode the PNG with GD or require a live database. It is
not evidence of a real WebDAV client/server exchange, MySQL concurrency,
production scanner behavior, disk-full recovery or durable operator recovery.
The parent owns central audit registration and qualification.
