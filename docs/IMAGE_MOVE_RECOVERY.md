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

Physical moves use exclusive hard-link creation followed by source unlink:
destination files cannot be replaced by POSIX rename semantics. Both paths must
support same-filesystem hard links. Unsupported/cross-filesystem moves refuse
and retain originals; no copy-and-delete fallback silently changes that guarantee.
A crash can temporarily leave two names. Recovery removes the other name only
when positive device/inode identity proves a shared file. On a platform unable
to prove that identity, both copies remain for operator reconciliation.

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
