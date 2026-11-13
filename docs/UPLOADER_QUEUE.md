# Durable uploader queue

The supported desktop workflow is to connect once, start capture, leave the
application running and press Finish once. A temporary inventory/network failure
does not end capture or create a new session. Both delegated and API-key uploads
use the same FIFO consumer. ZIP import still extracts locally; each request
carries one original and its optional thumbnails.

## Ownership and durable admission

`winapp/uploader/discovery.py` owns cheap local discovery. Windows change
notifications wake its one producer; a one-second reconciliation scan also
covers missed events and platforms without notifications. The producer continues
during thumbnail preparation, HTTP, renewal and retry waits. It never hashes
unfinished sources or starts network operations.

`winapp/uploader/watch_queue.py` extends `state_store.py` atomic publication.
Session `watch-drain.json` version 2 contains the exact target binding and scope,
original folder/recursion and startup exclusions, ordered source generations,
next sequence, explicit cutoff, retry state, last failure and completion barrier.
Import queues additionally retain the exact accepted path selection. They reuse
the existing accepted-job and destination ledgers.

Every item has an opaque stable ID, immutable session sequence, path/generation
signature, byte size, capture/birth/first-observed clock evidence and a nullable
hash represented by the empty string until known. State transitions require
file fsync, atomic replacement and exact readback before acceptance or
acknowledgement is reported. A stale writer cannot replace newer Finish or
acknowledgement evidence. Disk/readback failures preserve sources and pause
progress while the same owner retries discovery/recovery. Journals are bounded
at 32 MiB and 20,000 generations; exhaustion is a visible block, never eviction.

Newly discovered batches use a valid Steam local timestamp embedded after the
game prefix, otherwise native birth time, otherwise first observation. Equal
times use the normalized path and signature as deterministic tie breakers.
Modification time detects changes but never changes accepted order. A late old
file appends after already accepted/transferred work: neither this queue nor
filesystem events can prove an unseen absolute chronology or a wrong source
clock. Birth time can change during cloud restoration; a recognized capture
clock within the original interval can establish pre-cutoff eligibility.
On platforms without birth time, an unchanged pre-cutoff metadata-change time
can prove prior existence; it never becomes an ordering clock. A successful final
scan cannot prove files that remain invisible until after the interval is sealed.

The server appends inserted images through `sort_order`; automatic renaming uses
gallery order. FIFO therefore preserves observed admission order into those
append operations. A user's selected gallery sort/filter can display another
order. This change never reorders existing gallery contents.

Startup exclusions remain protected. Optional original fingerprints are read
only while the consumer is idle, with discovery continuing independently.
Changed same-path content becomes a new generation. Restored confirmed bytes
are deduplicated within the destination. A changed historical source whose
original bytes were never fingerprinted blocks for review rather than silently
uploading old content. A partially written accepted source retains its sequence
while its signature changes. Once dispatch begins, its source generation is
immutable and replacement content receives a later position.

## Head-only transfer contract

The existing linked target lease remains in place. A common native
`UploadPipelineLease` additionally serializes Watcher, import, recovery and
one-shot work in both modes within the canonical uploader profile. This is
deliberately stronger than a per-target local fence. Other computers/profiles
are outside that local proof; the server independently retains its gallery
mutation lock. A mode ticket alone is not a single-consumer lock.

Only the oldest unresolved item may hash/prepare its transfer, query inventory,
open an HTTP connection or upload. B cannot start any network stage while A is
unstable, missing, unreadable, waiting, unauthorized or unknown. Imports no
longer perform batch remote duplicate preflight; the accepted job's current
head performs that check. Local ZIP extraction and local preview are retained.

| State | Permitted next action |
| --- | --- |
| observed / stabilizing | Wait for the same source to become stable. |
| inventory / preparing | Resolve only this head's authorization and preparation. |
| dispatching | Finish the original request and persist its validated acknowledgement. |
| retry_wait | Continue local admission; retry this head after its persisted deadline. |
| auth_required | Retain capture and explain the required authorization action. |
| uncertain / blocked | Retain the head; no resend or later transfer without resolution. |
| acknowledged | Only now may the following sequence start its pipeline. |
| skipped | Explicit user disposition permits the next item; the original and unknown history remain retained. |

Thumbnail/preparation errors before dispatch are retryable. Write-ahead markers
are created after local preparation, immediately before HTTP dispatch. Linked
transport also completes authorization renewal and connection setup before this
write-ahead boundary; legacy urllib transport cannot prove its connect phase
separately and therefore treats an unclassified failure after dispatch as unknown.
Its typed DNS and connection-refused failures establish no-send and remain
automatically retryable; a generic timeout cannot establish that boundary.
Transport diagnoses retain status, phase, validated Retry-After, whether dispatch
may have begun, and the actual worker-completion event. A caller timeout never
releases an unfinished transport's ownership. Cleanup of a confirmed source or
temporary thumbnail directory cannot turn its acknowledgement into uncertainty.

Inventory failures are safe to retry because they do not store originals.
An exact application 429 refusal is pre-storage rejection. New authenticated
admission also emits `X-PHP-Gallery-Upload-Phase: rejected-before-store-v1` so
its bounded 503 storage/readiness refusal can be distinguished from an ambiguous
post-store failure. Generic upload timeouts, connection resets and proxy
502/503/504 remain unknown if dispatch may have begun. A hash match or inventory
absence does not prove the original operation ended. Automatic resolution of
that case would require a durable server operation/receipt protocol; current
`client_upload_id` only correlates parts within one request.

## Rate limits and automatic continuation

Previously inventory and upload both consumed 60 requests/IP and 600 globally
per 300 seconds, with a 300-second lock. Thirty inventory/upload pairs consumed
the entire IP budget. This is application policy, not evidence of a hosting
provider's limits.

The cost-specific policy uses 60-second windows and 60-second locks:

| Operation | Grant/account/gallery | Account aggregate | IP | Global |
| --- | ---: | ---: | ---: | ---: |
| Inventory | 120 | 240 | 240 | 600 |
| Upload (binding requests; aggregate originals) | 60 | 120 | 120 | 120 |
| Combined pre-authentication admission | — | — | 240 | 600 |

The account/gallery identity is server-verified before authenticated budgets are
charged. Subjects are installation-key HMACs; raw grants, accounts and addresses
are not stored by the limiter. Existing public linking, code exchange, media,
content-size, permission and readiness protections retain their own policies.
The global upload budget caps expensive storage at 120 originals/minute rather
than using a very large catch-all allowance.
The binding limits request bursts. Account/IP/global upload buckets atomically
charge every submitted original, preserving the server's existing multipart
capability without letting a 100-original request count as one storage unit.
Inventory and pre-authentication buckets count requests. The desktop's one
original per request makes both unit counts equal for its current pipeline.
Already recorded locks retain their original deadline until natural expiry;
deployment never clears limiter history to make a queue pass.

A 45-image session uses 45 upload and 45 inventory requests, two Watcher-key
control requests, two additional safe head checks, one renewal and one identity
read: 96 requests across their respective buckets. Two such clients sharing an
IP use 90 upload requests, 94 inventory requests and 188 requests in combined
admission; renewal/identity use separate existing budgets. These are admission
budgets, not measured production throughput or a hosting-capacity guarantee.

Actual limiter refusals propagate their remaining lock time. Exact window-end
comparisons permit the retry instead of creating another lock at the same
timestamp. A validated server hint takes precedence, with the five-second local
minimum preventing aggressive polling. Missing/invalid hints use exponential
5–300 second backoff. Attempts have no terminal count: intent survives repeated
limits, restart and sleep/wake. No inventory is repeated during a known wait.
Speculative Photos preload/adjacent prefetch yields while the pipeline is
waiting or blocked; explicit browsing remains available.

## Finish, refresh and recovery

Finish persists its first cutoff, retains the same queue and keeps reconciling
eligible pre-cutoff sources, including unfinished writers. Completion requires
no unresolved item, the stability grace, and a successful scan generation newer
than the last item transition. Empty `pending` or a stale frozen snapshot alone
is insufficient. Photo retry waits are not terminated by the older 300-second
finalization timeout. On restart, a verified, unambiguous same-target Finish
continues automatically. Screenshot Finish evidence never creates new legacy
Revoke authority; the existing post-restart confirmation rule remains separate.
Linked reports remain local; legacy report delivery retains its receipt owner.

The compact refresh icon requests immediate local reconciliation. Repeated
requests coalesce. An active upload completes normally; a live owner is never
replaced merely because progress is old. A safely stopped owner can resume its
original journal after target/lease validation. Refresh does not clear retry or
unknown markers, change target/scope, reopen capture after Finish, reset history,
or upload the initial snapshot.

Version-one recovery preserves the recorded cutoff; zero remains open rather
than becoming the last checkpoint time. Multiple pending paths in the old set
format have no provable FIFO order and block for review. A frozen old queue
without a cutoff is also unverifiable. No historical completed queue is migrated
just by installing source code.

For both journal versions, Windows recovery accepts `/` and `\` as alternate
separator spellings of the same recorded folder. Comparison otherwise remains
lexically exact, including case and dot components; POSIX retains its exact
original spelling. This reader compatibility does not change destination,
scope, session, source containment, recursion or FIFO verification.

Active update continuation retains its exact one-use nonce and version checks.
Idle helper tickets explicitly omit capture continuation on restart, so a stale
checkpoint is not misreported as an active-session mismatch.

Diagnostics retain current head ID/sequence, counts, producer/consumer state,
last scan/progress, retry deadline and an independent last failure beyond the
Activity tail. Refresh requests, coalescing and outcomes are recorded without
credentials or nonce values.

### Explicit uncertain-head review

**Resume…** and **Force Continue…** open the same head-specific review;
**Review pending uploads…** exposes retained work after restart. The review
compares the retained local generation with an authorized remote preview for
the bound target. A remote observation is labelled separately from an exact
persisted upload acknowledgement. Missing media, permission failures and absent
receipt evidence remain explicit unknowns. A thumbnail, gallery ordinal,
matching hash or inventory absence cannot confirm an upload.

**Confirm already uploaded (receipt)** requires the exact durable acknowledgement
for that head. **Skip photo** is a separate explicit decision: it records
`skipped_by_user`, retains the local source and uncertainty history, and counts
the item separately from successful uploads. No unknown operation is resent by
either action. Closing or cancelling review keeps the item pending. Each new
blocked head requires its own decision.
After an explicit decision is submitted, **Hide (saving decision…)** only hides
its progress; the already authorized durable publication completes normally.
It does not imply cancellation or rollback of that decision.
Preview evidence scrolls vertically in a short window; the decision and close
controls remain visible below the viewport.

After a worker restart, **Resume pending uploads** explicitly releases a safe
head for the same session even when no retained-stop flag was recorded. It
preserves the retry deadline and never resumes an unresolved dispatched head.

Recovery validates the current target, mode, session, item identity and source
generation under the existing pipeline and linked-target ownership. Decisions
are serialized with the consumer. A durable decision intent precedes linked
history review and the final queue transition; restart repairs that same intent
idempotently before any later item can transfer. A stale dialog cannot resolve
a replacement head. Readback failure remains unresolved rather than being
treated as successful publication.

Older version-two journals lack the optional source `modified_ns` and
`disposition_at` observations. Missing timestamps remain unknown; they are not
invented during restore. Preview validates the existing generation signature,
the opened file identity and any recorded content hash. A missing optional
timestamp alone does not invalidate an otherwise exact retained 1.1.1 source.
Unknown legacy generations still cannot supply a fabricated preview.

**Inspect folder (read-only)** reports filesystem findings separately from the
accepted queue. It does not invoke queue refresh, change startup exclusions,
cutoff, session, accepted items, hashes, receipts or status. Folder file counts
are not pending-upload counts.

Finish exposes known blocked states as **Needs attention** and keeps **Hide**
available. **Stop and keep pending** records the first cutoff and an explicit
retention decision, stops admission and preserves accepted work for review.
It does not revoke access, acknowledge an unknown upload or delete sources.
A transport that has not actually ended retains its owner; UI cancellation
cannot admit a competing writer. A bounded UI wait reports that retained owner
instead of claiming successful completion. A stopped journal requires explicit
review/resumption rather than automatically restarting capture.
Ownership remains held while the retained cutoff is being published and read
back. A failed publication keeps the consumer paused under that ownership;
another writer cannot enter through an uncommitted stop.
After a failed Stop save, explicit recovery also restarts reconciliation so
Finish can obtain its required fresh final scan under the original cutoff.
The save failure alone never resumes capture or transfers.
The immediate stop intent also suppresses optional source deletion for a late
successful response; the real acknowledgement is retained without discarding
the original the operator chose to keep.

Manual uploads to the same target do not repair a pending Watcher journal.
Unresolved matching work requires explicit review under the shared pipeline
fence, even when the operator can see an apparently matching remote image.

An earlier target-scoped unknown can block a new session's first, never-sent
head before inventory or upload. The review identifies the earlier operation
separately. An explicit prior-operation review preserves that unknown source
and permits only the new head's first dispatch; it never acknowledges or skips
the new source as if the older receipt belonged to it. Repeated relinking and
local file deletion do not clear this retained evidence. In older clients,
history-only **Review retained upload…** refuses active background work and
cannot reconcile an already uncertain Watcher head. **Keep files locally…**
deliberately closes the old session; it is distinct from the new retained stop,
which keeps the queue recoverable within that session.

The operator confirmed temporary installed-1.1.1 recovery by Stop Watcher,
Keep files locally, Review retained upload, reconnect, Start Watcher and a
new screenshot created after Start. That observation establishes renewed
new-source uploads only. It does not resolve the earlier upload or recover
the old FIFO, and is not the supported 1.1.2 recovery workflow.

## Verification boundary

Central WinApp discovery owns `test_watch_queue.py` and `test_uploader_fifo.py`;
the existing database fixture exercises actual limiter admission and exact
release times. Fixtures use isolated sources and controlled clocks, including
the synthetic 22-image outage case. Ordinary feature BASIC checks source syntax
and declaration documentation; it does not execute these runtime regressions,
qualify the installed Windows application, establish production deployment,
or explain unrelated Photos 503 responses without server evidence.
