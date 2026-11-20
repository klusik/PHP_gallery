# Catalog preservation during gallery creation

Project: PHP Gallery  
Author: Rudolf Klusal

Creating a gallery never deletes existing catalog rows. If a requested folder is
missing or cannot be observed safely but the catalog still owns that path (or a
descendant), creation returns `gallery_catalog_conflict` and HTTP 409. The
existing
gallery identifier is included in the canonical mutation metadata.

Do not repeatedly retry with the same folder name to perform cleanup. First
check
the configured storage, permissions and any recent FTP move/restore. Restore a
displaced directory to its original path, then use the existing discovery
workflow
to synchronize intended filesystem changes. Take a verified backup before any
explicit catalog maintenance. If the old gallery really should be removed, use
the existing explicit deletion/Trash workflow after restoring its storage where
necessary; creation is not a substitute for that workflow.

A deliberate Admin or linked WinApp create can use a numbered sibling when a
registered gallery has a healthy directory at the base path. The new gallery
keeps the requested title. Replaying the same operation key returns its
original receipt. Creation refuses an unregistered entry at the base path.
A stable readable parent enumeration is required to confirm absence. The
final mkdir is exclusive and requires the parent to still exist.

Coverage includes a disposable HTTP journey that displaces an uploaded gallery
with a child, checks original identities/rows/hash after a refused create,
restores
the folder, and continues editing and Trash restoration. No production storage
is moved by the test runner.
