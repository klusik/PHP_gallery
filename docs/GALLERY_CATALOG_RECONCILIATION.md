# Catalog preservation during gallery creation

Project: PHP Gallery  
Author: Rudolf Klusal

Creating a gallery never deletes existing catalog rows. If a requested folder is
missing or cannot be observed safely but the catalog still owns that path (or a
descendant), creation returns `gallery_catalog_conflict` and HTTP 409. The existing
gallery identifier is included in the canonical mutation metadata.

Do not repeatedly retry with the same folder name to perform cleanup. First check
the configured storage, permissions and any recent FTP move/restore. Restore a
displaced directory to its original path, then use the existing discovery workflow
to synchronize intended filesystem changes. Take a verified backup before any
explicit catalog maintenance. If the old gallery really should be removed, use
the existing explicit deletion/Trash workflow after restoring its storage where
necessary; creation is not a substitute for that workflow.

An existing, healthy directory still uses normal suffix selection for a deliberately
new gallery. An ambiguous directory read refuses creation, even without a catalog
match. A stable readable parent enumeration is required to call an entry missing;
this is a bounded observation, not a guarantee of storage health. The final mkdir
remains exclusive and does not recreate disappeared parents. Out-of-band storage
changes can still interrupt creation, but cannot trigger catalog deletion.

Coverage includes a disposable HTTP journey that displaces an uploaded gallery
with a child, checks original identities/rows/hash after a refused create, restores
the folder, and continues editing and Trash restoration. No production storage
is moved by the test runner.
