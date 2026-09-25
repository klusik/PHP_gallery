# Release qualification evidence

`RELEASE.md` remains the authoritative release workflow. This companion tool
records what still needs human review for an exact release artifact. It does not
run tests, generate a manifest, build the manual, package a release, or perform
Git/publication actions. `scripts/audit.php` remains the only automated test
orchestrator.

## Prepare a content snapshot

Finish source, documentation, PDF, and manifest generation first, following
`RELEASE.md`. Pass its cheap release/manifest/diff preflight and freeze the
inputs. Immediately before the final central release audit, initialize:

```text
php scripts/release_qualification.php init 0.104
```

Substitute the intended version, which must match `CMS_VERSION`. Save the
printed **Content SHA-256**; the examples below call this value `HASH`.
The record is stored at:

```text
cache/release-qualification/<version>/<fingerprint>/record.json
```

That directory is already covered by the repository's `/cache/*` ignore rule.
Repeated initialization of identical content preserves all evidence. A different
fingerprint creates a new record with every manual check pending and no automated
result. Earlier records remain available for comparison.

The fingerprint uses sorted repository-relative filenames and raw SHA-256 file
hashes, plus the target version and fingerprint-scope version. It reads actual
bytes rather than trusting Git revision, file size, modification time, or hashes
listed in the core manifest. The scope is deliberately conservative:

- All regular files under `.github/`, `app/`, `database/`, `public/`, `scripts/`, `docs/`,
  `tests/`, and `winapp/`, except local/runtime state and compiler intermediates.
- Registered root release inputs: `.htaccess`, `.gitignore`, `.gitattributes`,
  `index.php`, `install.php`, `reset.php`, `setup-gallery.php`,
  `config.example.php`, `deploy.bat`, `README.md`, `PATCH_NOTES.md`,
  `PATCH_NOTES_TEMPLATE.md`, `ARCHITECTURE.md`, `DATABASE.md`, `TESTING.md`,
  `CODEMAP.md`, `AGENTS.md`, `RELEASE.md`, `LICENSE`, and
  `release-metadata.json`, when present.
- The runtime bootstrap, core manifest, release metadata, manual source and PDF
  are required. Missing required files are errors, not successful qualification.

`fingerprint.php` owns the exact exclusions: caches, installation data/media,
local agent folders, local `config.php` files, custom installation CSS, logs,
Python bytecode, and LaTeX auxiliary outputs. No Git command is needed. Symlinked
inputs are refused. Newly added files and deletions within the scope change the
fingerprint, including files not yet added to Git.

Any relevant content change invalidates *all* approvals and audit evidence for
the current content. Rebuilding a PDF with different bytes invalidates visual
approval even when its size, version and modification time are unchanged.
An exactly byte-identical rebuild retains the same identity. Line-ending
changes count as content changes; copy exact artifacts between workstations.
The manifest itself is included, so generate it before initialization.

## Attach the central audit

With the initialized source tree held unchanged, run the one authoritative
release profile and attach its JSON report:

```text
php scripts/audit.php --profile=release
php scripts/release_qualification.php record-audit 0.104 --fingerprint=HASH
```

The default input is `cache/test-audit/latest.json`. Use
`--report=cache/test-audit/<run-directory>/report.json` for a particular run;
absolute paths are also supported. The importer requires the release profile,
every currently registered release suite exactly once, a consistent result
schema, exact before/after content fingerprints, and a run started after
initialization and already completed.
`quick`, `full`, partial suites, older runs and inconsistent summaries are refused.
Record failures and blocked runs too: they are useful evidence, never approval.

This command copies and hashes the report, preserving suite results, environment
information and skip reasons even when the runner later replaces `latest.json`.
It does not read individual passing suite logs or run any test command.
Existing reports from before initialization cannot be retroactively certified.

The central release report must contain top-level `source_fingerprint_before`
and `source_fingerprint_after` strings, each obtained from
`\PhpGallery\ReleaseQualification\snapshot($root, $version)['fingerprint']`
before and after the suites execute. Both must exactly equal the current
qualification fingerprint. Missing stamps, another source tree, or source
changes during the run refuse attachment, even if the report says PASS.
Legacy unstamped reports cannot be retroactively bound.

Keep the tree frozen through the audit and attachment. If it changes,
initialize the new content and follow `RELEASE.md` to rerun the release profile.
Local JSON records are maintainer evidence, not tamper-proof signatures or
publication authorization.

## Record actual human reviews

Use one of `pending`, `pass`, or `fail`. Each write requires the exact fingerprint
reviewed, a named reviewer, and concise evidence. The command rejects a stale
fingerprint rather than attaching a review to a newly changed artifact.

| Check ID | Required review |
| --- | --- |
| `pdf-title` | Rendered title page, target version and edition date. |
| `pdf-contents` | Every table-of-contents page, labels and layout. |
| `pdf-index` | Index pages, entries, references and layout. |
| `pdf-changed-pages` | Every changed feature section; identify physical PDF pages. |
| `pdf-links-layout` | Bookmarks, clickable internal links, page breaks and general layout. |
| `browser-smoke` | Applicable critical workflows, browser/device and isolated environment. |
| `post-publication-smoke` | Published updater discovery/integrity, migration, login, public gallery and integrity/status checks when performed. |

For example, these commands describe pending or failed review evidence:

```text
php scripts/release_qualification.php record 0.104 pdf-index pending --fingerprint=HASH --reviewer="Reviewer name" --evidence="Awaiting visual inspection of physical PDF index pages."
php scripts/release_qualification.php record 0.104 pdf-title fail --fingerprint=HASH --reviewer="Reviewer name" --evidence="Physical page 1: edition date clips into the footer."
```

After a person has actually inspected the current artifact, a pass can be
recorded with the same syntax:

```text
php scripts/release_qualification.php record 0.104 pdf-title pass --fingerprint=HASH --reviewer="Actual reviewer" --evidence="REPLACE with the actual inspected pages, viewer and observed result."
```

Replace the evidence text; do not use the example as an approval. Include preview
directory/page references where useful. A pass is an explicit human attestation.
PDF text extraction, a successful compiler, generated previews, automated
browser fixtures, and an audit PASS do not supply it. Inspect links/bookmarks
in a PDF viewer because raster pages cannot demonstrate interactive behavior.

For `browser-smoke`, describe the applicable release-specific matrix from
`TESTING.md`, browsers/devices, environment and observed results. Exercise affected
workflows; for relevant panel actions include unchanged URL, open panel and
controls still working after fragment replacement. Do not substitute a
synthetic map fixture for a full authenticated workflow. Keep unavailable checks
pending with a reason; manual reviews have no silent skip or waiver state.
If a particular workflow is not applicable, state why in the evidence for the
applicable matrix; do not describe an unperformed check as passed.

Evidence must fit on one line (reviewer: 160 bytes; evidence: 2,000 bytes).
Do not include credentials, tokens, private gallery contents or production
secrets. Earlier status changes are retained in the record's history.

## Render repeatable PDF previews

Install Poppler's `pdftoppm`, or use a compatible local installation providing
that command. Run from the repository root after the manual is built:

```text
php scripts/release_qualification.php render 0.104 --pages=1-4 --dpi=110
php scripts/release_qualification.php render 0.104 --pages=120-124,140 --dpi=110
```

These are example **physical, one-based PDF page numbers**, not printed Roman or
Arabic page labels. Choose the actual contents, index and changed-section pages
for the current manual. Supply an absolute renderer path when it is not on PATH:

```text
php scripts/release_qualification.php render 0.104 --pages=1-4 --renderer="C:\Program Files\MiKTeX\miktex\bin\x64\pdftoppm.exe"
```

The tool invokes `pdftoppm` using an argument array, without a shell, once per
page. It fixes PNG format and the chosen DPI (72-200, default 110), deduplicates
page numbers, permits at most 200 pages per command, and limits each renderer
process to 30 seconds. Each attempt writes a fresh directory:

```text
cache/release-qualification/<version>/<fingerprint>/previews/<attempt>/
    manual.pdf
    preview.json
    page-0001.png
    page-0001.log
```

The PDF copy, original PDF hash, complete source fingerprint, exact page
selection, DPI, renderer name and generated PNG hashes are retained. The tool
verifies that each renderer output is a PNG and that the source identity still
matches after rendering. Use the same PDF, page selection, DPI and renderer
version on another workstation to repeat the preview; renderer versions may
produce different PNG bytes. Preview generation never changes review states.

If rendering fails or the executable is missing, the command exits 2 and
retains its failed attempt metadata/log. Move the exact source/artifact snapshot
to a workstation with a working renderer, confirm the same fingerprint and
rerun the command. Keep visual review pending until a person has inspected the
output. Deleting ignored cache removes local evidence and previews, not source
artifacts; preserve that evidence separately if release retention requires it.

## Read the handoff

```text
php scripts/release_qualification.php check 0.104
php scripts/release_qualification.php check 0.104 --json
php scripts/release_qualification.php check 0.104 --phase=post-publication
```

The report shows the version, exact fingerprint, captured automated result and
suite summaries, manual states/evidence, outstanding reviews and environment
gaps. Skips nested inside a passing suite remain visible. With changed content,
the current check cannot use the old record and reports missing current evidence.
It never silently changes an old approval to apply to the new artifact.

`check` exits 0 only when all checks for the selected phase passed, a current
release audit passed, and no reported coverage gaps remain. It exits 1 for
incomplete evidence (including skips) and 2 for malformed input, missing required
artifacts or tooling errors. Pre-publication is the default; pending
post-publication smoke remains visible for later work without making the
pre-publication phase impossible to complete. Post-publication includes all
checks. A skipped environment check remains a gap even if the central audit
returns PASS. The tool supplies no override that converts a skip into a pass.

Qualification is evidence for the selected phase, not permission to publish.
Package inspection and the other operator duties in `RELEASE.md` still apply.

## Central integration and regression coverage

`RELEASE.md` initializes qualification after final artifact/manifest generation,
then attaches the authoritative release report. `scripts/audit.php` captures
`source_fingerprint_before` before its suites and `source_fingerprint_after`
afterward. Missing identity blocks release consistency; changed identity fails
it. The console and Markdown report expose outstanding human reviews separately
from automated status. They never create manual approval.

The existing PHP regression suite discovers
`tests/release_qualification_test.php` automatically. The audit-runner contract
also checks unchanged, changed and unavailable source identity. There is no
second runner. Do not add qualification `check` as a mandatory deterministic
audit suite: human evidence intentionally lives in ignored local cache.

After the actual release audit, attach its report with `record-audit`, collect
actual manual evidence, and use `check` output in the handoff. Retain visible
post-publication follow-up. Regenerate the manifest and reinitialize qualification
after relevant source or artifact changes.

The focused fixture exercises pending/pass/fail transitions, explicit reviewed
fingerprints, exact-byte invalidation, retained history, CLI exit codes, audit
report imports, nested skips, phase separation and renderer failure reporting.
Its synthetic reviewer results are confined to a temporary test directory and
do not qualify any actual release.
