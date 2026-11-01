# Gallery consistency reports and selected repairs

Open **Admin > Maintenance Center > Gallery consistency**. The existing
Maintenance Center has native **Installation**, **System tools** and **Gallery
consistency** tabs; the main vertical menu gains no item. System tools
contains the former Overview maintenance groups, including media renaming and
thumbnail work. Overview now keeps only Overview and Galleries. Saved
maintenance query links redirect to the corresponding System tools group. The
Media and cache shortcut opens the same consistency tab. URLs retain the
selected tab through reload/history. **Analyze gallery** starts an explicit
browser-driven report. It does not import, rename, delete or generate media.
Only its private PHP-session checkpoint is saved. Expected ownership uses
fixed-size SHA-256 path keys and integer IDs rather than retaining every
repeated long original/thumbnail path. This is path ownership evidence, not
content verification; full path bytes are still supplied to guarded filesystem
owners. Already-running old session jobs retain old keys. The report includes
private and unpublished physical galleries; administrator authentication and
CSRF protect every job request.

## Choose the scope before analysis

- **Names and paths (DB only)** is selected initially. It compares primary
  names, recorded folder paths and parent relationships without querying the
  photograph catalog or reading physical directories. It detects catalog
  discrepancies; it does not establish whether the recorded files exist.
- **Files and thumbnails** checks actual originals, derivative headers,
  dimensions, modification times, metadata and unindexed disk files. It does
  not hash every original or decode image pixels. Same-size content changes
  and corruption beyond readable headers require deep content verification.
- **Deep content verification (slow)** additionally reads original SHA-256
  checksums and performs raster comparisons. This is an explicit expensive
  operation for large collections. Refused decoding stays unverified.

The completed report displays its actual scope independently of the next
analysis selection. Gallery disclosures show current and proposed title, final
folder segment, complete path, parent and sidecar changes, plus an explicit
action for each repairable finding. Parent normalization also affects
descendant paths. These are proposals; the existing mover revalidates actual
physical destinations before writing and refuses conflicts. Empty values are
labeled as missing or not set. Older completed session reports gain the same
proposal presentation from their captured catalog snapshot without a new disk
scan or changed findings. Slashes retain their hierarchy; normalization
applies per segment. A raw readable folder can match the visible title but
still need sanitization, for example `Holiday Photos` to `holiday-photos`.

Findings are summarized by reason, with exact finding/photo counts and one
short repair action or manual-review status. Open Photos / files for examples:
variants of one known photo share its catalog filename and size/format list.
Technical cache filenames, including path hashes, stay inside Technical details.
Unassigned derivatives remain a separate manual category; similar names never
establish a source owner. Records for a size/format outside the current policy
may be historical leftovers, not damaged pixels, and have no automatic deletion.

Each question mark explains Now, Why it matters and After repair. Hover or focus
opens the anchored help; activation pins it, Escape returns focus, and outside
click dismisses it. Native disclosures remain available without enhancement.
The controller resolves only the bounded displayed photo IDs from the catalog,
checking that each still belongs to the report gallery. Missing identities use
an explicit photo ID. Names-only reports perform no photo lookup. No original
files are opened for this presentation and no repair/export findings change.

Across the file and deep scopes the report checks:

- Gallery directory existence/readability, the sanitized primary title against
  its physical leaf, immediate parent catalog membership and `gallery.json`
  title/readability. Translated titles do not determine directory names.
- Original existence, stored size and exclusive derivative naming ownership.
  Deep mode also compares readable SHA-256 against the stored source checksum.
  Missing originals are explicit failures.
- Every variant expected by the current source/format policy, its modification
  time, image dimensions/ratio and associated metadata. It also reports duplicate
  or unsupported metadata and metadata whose source row no longer exists.
- Small orientation-aware RGB comparisons between decodable originals and
  derivatives. Large differences are **manual review**, never automatic
  replacement authority. Similarity is not proof of pixel identity or historical
  provenance. Naming hashes identify relative paths, not image contents.
- Supported images physically present but not indexed, unowned files in `thumbs`,
  symbolic links and pending image-move recovery records. Symbolic links are not
  followed. Hidden/internal payloads and non-image PDF/music attachments retain
  their existing domain owners; this is not a general backup/integrity scan.

Unavailable/refused decoders, RAW content comparison, missing originals,
ambiguous ownership and unowned cache files remain visible manual findings.
The report never treats unavailable evidence as verified health. A corrupt
file with a readable header may require decoder inspection; visual similarity
remains a heuristic. There is no claim of an atomic snapshot against external
filesystem writers. Catalog changes during analysis mark the report stale.
Source byte leases for affected photographs are captured during analysis and
checked again before a selected source repair.

## Progress, selection and export

Names-only work batches up to 256 galleries; file work admits up to 32 sources
and physical traversal up to 2048 entries per request. New work admission
stops after 0.75 seconds, while an already admitted decoder/read finishes
under its existing limits. Receipts confirm actual completed records. Physical
traversal has an unknown total, so that phase uses an indeterminate bar plus
the actual inspected-entry count. No timer estimates completion. Only affected
galleries/folders appear, in compact expandable rows. Results are paged by 40
galleries; each disclosure samples at most 12 photo/file examples, shared
across finding categories. The authenticated JSON export
retains all findings and excludes raw access-bearing catalog rows. Selection
survives result pagination.

**Repair selected** uses the selected report groups. **Repair all available**
selects every repairable group, including those on other pages. Both first
prepare a server-owned task queue; the following requests perform one task
each. Manual findings are retained and do not authorize file deletion. Counts
refer to repairable findings in the list and deduplicated tasks in repair
progress.

Repairs use the existing gallery writer lease, schema admission, scanner,
subtree mover and thumbnail publisher. Each task revalidates the catalog lease
and source bytes. A pending image move blocks repairs until its separate
recovery workflow is reconciled. Failure stops the queue with a retained
failure receipt; already completed tasks are not described as rolled back. Private failure
receipts retain exception class, source basename and line for diagnostics,
without raw exception messages, SQL or absolute paths.

A successful batch removes only its confirmed findings from the active list.
Empty gallery rows disappear; unselected and manual findings remain. Select the
next subset or **Repair all available** immediately, using the same report.
Completion refreshes catalog identities and path proposals from DB only, without
repeating photograph inspection or a disk scan. The export retains repair
receipts. Each new batch advances the receipt sequence so an older delayed
request cannot admit another batch. Existing completed checkpoints adopt this
remaining-list behavior when opened. Failed/uncertain work stays visible;
external catalog changes still require a fresh report before further writes.

- Gallery repairs derive the primary title from disk, create missing ancestors,
  canonicalize the leaf with the existing sanitizer, synchronize the parent and
  rewrite the title sidecar. Existing compatible readable titles are retained.
- Unindexed originals are registered individually. An unindexed photo directory
  is imported with its missing ancestors and canonicalized physical name.
- Missing, stale, invalid or undecodable owned variants use the existing browser
  ZIP/worker/upload pipeline. The server owns the exact image/variant ticket,
  validates source identity and publishes only requested variants under the
  gallery writer lease. Valid unrelated variants retain bytes and timestamps.
  Progress counts a task only after server confirmation. The browser processing
  bar sits directly below the main workflow progress, above the findings list.
  A lost upload reply first reconciles persisted files; confirmed output is not
  generated again.
  Browser errors remain visible with no PHP generation fallback. Keep the tab
  open. Unsupported RAW/HEIC sources need a separate explicit compatible tool.
  Metadata-only findings refresh metadata without deleting thumbnail files.
- Changed originals are rescanned from disk; their old derivative state is
  invalidated through the existing scanner before regeneration.

The session persists an executing receipt before work starts. A lost response
requires **Check progress / continue**, which first reads status. An executing
receipt is uncertain and is never replayed automatically. A new read-only
report can reconcile an interrupted task once the gallery writer becomes
available. Closing the browser leaves remaining work paused; session expiry
requires a new report. Switching tabs likewise leaves remaining browser-driven
work paused; returning offers passive progress reconciliation. The page needs
JavaScript for bounded operation. Installation maintenance and consistency
keep separate jobs.

## Disk-authoritative imports

Discovery and import use the physical directory as the name authority. A
sidecar title survives only if its sanitized form identifies that same leaf.
Otherwise the imported primary title is the disk leaf. Newly imported leaves
are canonicalized deepest first with the guarded subtree mover, preserving
stable catalog IDs and descendant paths. Occupied or competing canonical names
are refused before importing selected rows; numeric suffixes are not invented.
Existing unrelated galleries are not silently swept during import. Use the
consistency report to inspect and explicitly repair historical discrepancies.

The older **Check missing thumbnails** remains a derivative maintenance tool;
it is not a complete original-file or folder-consistency audit. **Refresh
thumbnail database** is a mutation that may remove wrong-ratio files.
**Integrity** checks application files, not user photographs. Keep backups
independent of all three tools.

## Targeted thumbnail generation engine

In **System tools > Media and cache**, **Create missing thumbnails** respects
the same **Browser-side thumbnail rebuild** checkbox as Create all. A checked
box uses the existing browser workers and uploads their acknowledged variants;
a failure stays visible and never silently switches to PHP generation.
Clearing the box explicitly selects the bounded server writer, whose decode
memory budget still applies. Both paths retain the missing-only scope. The
global missing-thumbnail warning links here so the engine choice is visible
before creation. Consistency repair always uses browser workers, independently
of this checkbox. Its result shows confirmed variant counts and the remaining
findings. Continuing with another subset does not require a new analysis.
