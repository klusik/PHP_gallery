# Recoverable image moves

Project: PHP Gallery  
Author: Rudolf Klusal

Apply pending migrations before using image moves. The new
`gallery_image_move_journal` table preserves intent independently of gallery rows;
there are deliberately no cascading foreign keys that could erase recovery
evidence after unrelated deletion.

Lifecycle: `prepared -> moving -> db_committed -> finalized`. A verified
pre-commit rollback ends in `rolled_back`; uncertain state becomes
`needs_reconciliation`. The separate `database_committed` marker is written in
the **same transaction** as image ownership and cover changes, and survives a
later reconciliation error. Never infer commit from whether a browser got a reply.

The manifest stores original/destination relative paths, image IDs, byte lengths
and SHA-256 identities. It is private recovery data, not a public diagnostics
payload. New moves refuse while either gallery has an unresolved journal.
Connection-scoped, non-waiting locks serialize image moves and recovery across
sessions. They cannot lock FTP operators or unrelated storage maintenance.

When a move fails, the Organizer warning now identifies the phase and a safe
reason, such as `file transfer: the destination directory cannot be created`
or `file transfer: the original file could not be moved to its destination`.
It includes the original/derivative manifest entry number and journal operation
ID when available. The `gallery.image_move_failed` Admin log event records the
source/destination gallery IDs, requested image count, phase, reason code,
operation ID, entry number and file kind. Its private `debug` context additionally
records the failed source/destination path resolution or transfer step, exact
gallery-root-relative and absolute file paths, exception class/message, PHP
source file/line and a bounded call trace without arguments. It also captures
the configured storage root's real path, each file parent's real path, the
stored source/destination gallery paths, and whether each file exists or is a
symbolic link at failure time. Native filesystem
warnings are retained as exception causes. Database exception messages are
withheld because they may contain SQL or credentials. Do not share this Admin
log entry outside trusted operators; the Organizer's on-screen warning retains
only the safe reason and operation ID. A failure before the journal is written
has no operation ID and need not appear in the pending list.
An ownership transaction failure is reported separately from a file transfer
failure; if automatic reconciliation also fails, the recovery reason takes
priority and remains on the pending journal for the operator.
Journal path checks normalize legacy separators in stored gallery folder paths
before comparing them with canonical manifest paths. A real escape, symlink or
changed parent directory still fails with its own safe reason code.

Physical image moves use `rename()` on the original file, after checking that
the destination is unoccupied. The moved file is verified against the journaled
size and SHA-256 before database ownership changes. The folder tree and files
remain authoritative; no hard or symbolic links are created. Gallery locks
serialize application writers. External filesystem writers must be stopped
during a move because PHP has no portable atomic no-replace rename. Recovery
uses the database commit marker to choose the required location. If an older
interrupted operation left two regular files, recovery removes the extra file
only when both match the journaled bytes. Missing, changed or unverified files
remain for operator reconciliation. Gallery-folder relocation separately uses
a physical directory rename; this image-move journal does not cover that workflow.

## Operator workflow

Admin > Maintenance > System health and Runtime Diagnostics show the same
bounded, read-only pending-move snapshot. Discovery calls the existing journal
reader only when maintenance details or authenticated Diagnostics are opened;
ordinary gallery navigation and public requests do not load the pending queue.
Missing/unknown journal schema skips the row query and displays an Action state.
Read failure is unknown, never a claim that the queue is empty.

The card and copyable diagnostics report expose at most 30 operation identifiers,
source/destination gallery IDs, allowlisted lifecycle states and safe error
categories. This is a capped sample, not a complete backlog count. Private
manifests, paths, file fingerprints and database exceptions are excluded.
An unfinished operation may still be running: wait for active work before
following the recovery procedure below. Opening either surface never scans
files, acquires a recovery lock, moves files or automatically recovers anything.

1. Stop competing storage maintenance and take a verified backup.
2. Run `php scripts/reconcile_image_moves.php --list` for a bounded, read-only
   list of pending operations. Output excludes paths, image hashes and raw errors.
3. Inspect the affected source/destination galleries and storage availability.
4. Explicitly run
   `php scripts/reconcile_image_moves.php --recover=<operation-id> --apply`.
   Uncommitted intent rolls files back; committed intent finishes destination
   verification, cover/sidecar refresh, and finalization.
5. Repeat the same operation after correcting storage conditions if necessary.
   Finalized/rolled-back operations are no-ops on replay. Unrecognized, changed,
   missing or conflicting files are retained rather than overwritten.

This first journal covers **image moves**, not gallery-folder relocation, uploads,
FTP changes, deletions or off-host disaster recovery. Journals are retained as
evidence; there is no automatic deletion of unresolved records. A migration of
those other workflows must use their own complete mutation boundary.

The isolated `admin_maintenance_health_test.php` verifies the shared registration,
lazy/three-state discovery, 30-entry cap, redaction and both rendered surfaces
in the four maintained languages. This is service/view fixture evidence, not
a live-journal inspection or a recovery execution test.
The development run on 2026-09-20 passed 543 assertions with 96 localized
surface renders and two escaping renders on PHP 8.3.30. The central runner
discovers this new test normally; no extra environment requirement is needed.
