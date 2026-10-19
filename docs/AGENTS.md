# Documentation and manual instructions

Read [the root contract](../AGENTS.md) first. These rules apply to documentation
anywhere in the repository, including root Markdown and all four manual sources.
Use [checked authoring examples](AGENT_AUTHORING.md) for source contracts.
Release execution belongs to [RELEASE.md](../RELEASE.md) and
[the release lifecycle](RELEASE_LIFECYCLE.md).

## Maintaining instructions

Keep root AGENTS at approximately 6-10 kB. Put cross-cutting essentials there
and
keep its explicit area map current; nested files are not automatically
discovered
when a session starts from the root. Keep each root-to-directory instruction
chain comfortably below the documented default combined 32 KiB budget; linked
references are read explicitly. This is an authoring budget, not a config
change.
[OpenAI documents instruction discovery and its combined limit](https://learn.chatgpt.com/docs/agent-configuration/agents-md).

Update existing topical rules/examples when learning from CI. Write affirmative,
actionable guidance and remove duplicate formulations. Keep current operations
separate from dated evidence, completed phases and finite transition exceptions.
Historical records never authorize writes or install changed CI authority.
Preserve the existing checked-example markers and examples: the registered test
parses them directly. Measure first-pass batches/repair commits/avoidable FULL
starts with existing reports; do not claim effectiveness from shorter text
alone.

## Continuous Documentation Synchronization

Every implementation batch that materially changes product behavior,
administrator
operations, security or compatibility policy, runtime ownership, migrations,
deployment, updater behavior, CI, testing, tooling, or the developer workflow
must review and update its permanent documentation **during that same batch**.
This applies to maintainer-facing infrastructure changes even when the public
UI does not change. Do not defer such explanations to a future release branch.

Update the relevant Markdown references (for example `README.md`,
`ARCHITECTURE.md`, `CODEMAP.md`, `DATABASE.md`, `TESTING.md`, and applicable
`docs/*.md`) and the corresponding sections of **all four** maintained
`docs/PHP_Gallery_Manual*.tex` sources whenever their current behavior,
instructions, safety requirements, or architecture would otherwise be stale.
A change to generated-artifact preparation or CI gating, for example, belongs
in the TeX manuals' developer/maintainer reference, not only in `AGENTS.md`.
Describe the current supported behavior in the existing topical sections;
never append an issue-by-issue change log to a permanent manual.

Review documentation impact before the final source commit and hosted
preparation and
the authoritative hosted handoff qualification. The documentation updates belong
in the same
source candidate as the implementation. If no TeX section is affected by a
truly internal change, explicitly record `No TeX manual impact` with a brief
reason in the implementation handoff or issue instead of silently omitting it.
An internal-only or CI-only label does not by itself justify omission.

During ordinary development update **TeX sources only**, preserving existing
edition version/date markers. Do not compile, rewrite, inspect, or commit
manual PDFs as part of routine source changes. The release workflow only
updates release/version metadata and compiles existing TeX to PDF; it does
not author the technical/manual prose. `PATCH_NOTES.md` remains reserved
for explicit maintainer requests or the separate release preparation process;
do not modify it for ordinary feature, bug-fix, or documentation batches.

## LaTeX Manual Typography

### TeX and Markdown source readability (advisory)

Prefer source lines near 80 display columns; consider semantic wrapping at
81-100
and actively seek a readable split above 100. Wrap edited prose at existing
spaces
without adding/removing paragraph breaks or changing rendered meaning. Keep TeX
non-breaking `~` bindings, commands/arguments, math, URLs and literal
environments
intact. Preserve Markdown headings, tables, link destinations, inline/fenced
code,
list continuation indentation and explicit hard breaks. Apply focused edits to
the passage being changed; do not reformat entire manuals automatically. Long
indivisible constructs are valid exceptions. This is advice, never a CI gate.

### All manual language editions

Every relevant product, operational, developer, testing, or release
documentation change must keep all four maintained source editions aligned:
`docs/PHP_Gallery_Manual.tex` (English), `docs/PHP_Gallery_Manual_CZ.tex`
(Czech), `docs/PHP_Gallery_Manual_DE.tex` (German), and
`docs/PHP_Gallery_Manual_SV.tex` (Swedish). Keep feature coverage, compatibility
notes, operating instructions, and developer workflows equivalent across
languages. `scripts/prepare_release.php` updates only the English edition
markers, while hosted release preparation aligns all four version/date markers;
neither writes missing manual prose. Updating English alone is not a completed
manual-source handoff.

During ordinary development, edit and review the Markdown/LaTeX sources without
rebuilding tracked PDFs after each code or documentation change. PDF artifacts
may intentionally lag behind their sources until final release preparation;
routine documentation work does not require a local TeX installation. Build all
four maintained PDFs together once after the final release source/metadata
edits, before the final manifest and exact-candidate release audit. The
GitHub-hosted release workflow owns this batch compilation and checks compiler
success and relevant warnings. Use the local commands in `docs/LATEX_BUILD.md`
only when an explicit local build is requested or a concrete compilation/layout
failure needs diagnosis. Later changes to frozen release inputs require a new
preparation and qualification run.

Do not routinely read generated manual PDFs, extract their text, render pages,
take screenshots, create contact sheets, or perform visual PDF review after a
successful LaTeX build. The maintainer explicitly considers that redundant work.
Validate the source, compiler result/warnings, and existing automated release
consistency checks instead. Visual inspection is appropriate only when the user
explicitly requests it or a concrete build/layout problem needs diagnosis;
inspect only the affected pages in that case. This repository-specific
preference overrides generic PDF skill review workflows and older release
visual-review suggestions. Routine release preparation must not introduce a
human PDF visual-approval gate.


The administrator manual is permanent product documentation, not a release-news
feed. Do not add version-by-version “What is new”, patch-note, or
release-history sections near the beginning of `docs/PHP_Gallery_Manual.tex`.
Put release history in `PATCH_NOTES.md`; if a future manual genuinely needs
historical material, add it deliberately as a documented appendix rather than
inserting news before the usage guide. Before release, confirm the manual
proceeds from its purpose/reading guide into the usage guide without a
release-news block.

For human-readable prose in `docs/PHP_Gallery_Manual.tex`, do not leave short
function words stranded at the end of a rendered line. Use LaTeX non-breaking
spaces (`~`) to bind them to the following word or visible phrase. Apply this
throughout the manual, including headings, captions, list text, footnotes, and
explanatory table text.

As a general rule, bind ordinary standalone one-letter words (normally `a` and
`I`) and ordinary two-letter words forward. Also bind common three-letter
function words where appropriate, including `the`, `and`, `for`, `but`, `nor`,
`yet`, `are`, `was`, `can`, `may`, `has`, `had`, `did`, `not`, `per`, and `via`.
Apply the rule case-insensitively in prose and chain bindings naturally, for
example `a~thing`, `the~thing`, `on~the~menu`, and
`The~gallery needs to~be~formatted like this.` Keep all-uppercase technical
abbreviations and operators such as `UI`, `IP`, `OS`, `DB`, `ID`, `AND`, `OR`,
and `NOT` untouched regardless of length.

Do not insert these typography tildes into LaTeX commands or machine-readable
material such as URLs, filesystem paths, setting names, labels, references,
citation keys, index keys, math, code/literal strings, or
`lstlisting`/verbatim-style environments. A short word at a source-code line
wrap still belongs to the following prose word, so the non-breaking binding must
survive source wrapping rather than only same-line text. When editing or adding
manual prose, preserve this convention instead of relying on TeX to choose a
line break automatically.

## Patch Notes Guidelines
Patch note generation rules are documented in `PATCH_NOTES_TEMPLATE.md`. When
creating release notes, follow that template and the existing `PATCH_NOTES.md`
style. Do not edit existing historical entries unless explicitly requested.

## Release Preparation Artifact Policy

Release preparation must not create deployment folders, release ZIPs, clean
packaging staging trees, checksum files or handoff bundles in `deploy/` by
default. The maintainer does not need these generated artifacts. Do not create
them unless the user explicitly requests a particular package or archive. Keep
central audit and qualification evidence in their existing ignored `cache/`
locations.

The only normally needed distributable build artifact is the Windows installer
in `winapp/dist/`, and rebuild it only when shipped WinApp code, assets,
dependencies, runtime binaries or installer/build behavior changed since the
previous installer. CMS-only release markers, documentation changes and
test-only fixes do not require a new installer. Preserve the independent WinApp
version unless the user requests its change; when no installer change is needed,
leave `winapp/dist/` untouched. This default overrides packaging suggestions in
`RELEASE.md`; release metadata, all four manual editions/PDFs, manifest
freshness and the authoritative release audit remain required.

## Release Manifest Handoff Requirement
Every change to an updater-managed release file must refresh
`app/core-manifest.json` before a production archive, affected-files ZIP,
release commit, or handoff is created. Hosted candidate preparation runs the
canonical `php scripts/prepare_candidate.php` after the source commit, then its
read-only `--check`; it generates the manifest last after runtime and inventory
preparation. The production builder refuses stale integrity data before staging
and verifies every selected file against its actual source bytes before
publication. It does not repair generated state. If an affected-files ZIP
contains any managed application file whose hash is covered by the manifest,
include the freshly generated `app/core-manifest.json` in that ZIP as well. A
stale manifest makes an otherwise valid GitHub release deterministically
uninstallable.
