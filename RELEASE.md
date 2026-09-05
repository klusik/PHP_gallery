# PHP Gallery Release Workflow

This document is the authoritative maintainer and agent playbook for preparing and qualifying a PHP Gallery release. `AGENTS.md` points here for release work. The mechanical release scripts intentionally do not replace editorial review, release-note writing, manual inspection, Git review, packaging inspection, or post-publication smoke testing.

## Core rule

Audit profiles are alternatives, not a staircase.

For an actual release, do **not** run `quick`, then `full`, then `release`. Complete the release preparation workflow below and finish with exactly one:

```text
php scripts/audit.php --profile=release
```

The release profile already contains the deterministic coverage from `full`, plus browser integration when available, release consistency, manifest freshness, and Git whitespace validation. If a source or release artifact changes after a successful release audit, regenerate the manifest and rerun only the release profile.

## Release phases

### 1. Establish the release scope

Before changing version markers:

1. Work from a clean, reviewable release branch or working tree.
2. Identify the exact previous stable tag and the intended target version.
3. Compare the current work with the previous release tag. Review every changed path and intervening commit.
4. Pay particular attention to:
   - `database/migrations/` ordering and upgrade compatibility;
   - browser entrypoints and cache-busting import/version changes;
   - configuration/default changes;
   - translations and language catalogs;
   - updater/package policy and protected files;
   - generated artifacts;
   - user-facing behavior that must be reflected in documentation and the manual.
5. Decide whether the release is patch, feature, or larger-scope work based on the actual diff. Do not infer the version solely from the branch name.

If Git metadata is unavailable, record that the previous-tag comparison is a coverage gap instead of inventing history from the ZIP contents.

### 2. Run deterministic release preparation

Run once after the target version is known:

```text
php scripts/prepare_release.php X.Y.Z
```

For an exact release timestamp, use:

```text
php scripts/prepare_release.php X.Y.Z --released-at="2026-09-05 21:30:00"
```

The preparation script updates only explicitly registered mechanical markers:

- `app/bootstrap.php` runtime `CMS_VERSION`;
- `README.md` current-version marker;
- `TESTING.md` guide-version marker;
- `DATABASE.md` current schema-document marker;
- the `ARCHITECTURE.md` `CMS_VERSION` example;
- `docs/PHP_Gallery_Manual.tex` version and edition date;
- `release-metadata.json` entry and `v_<version>` tag value;
- a new `PATCH_NOTES.md` scaffold when the target version has no entry yet.

It deliberately does **not** replace arbitrary version-looking strings. Historical references such as "Version 0.95 introduced..." must remain historical.

The preparation script also deliberately does **not**:

- write final patch-note prose;
- decide whether a migration or documentation statement is still accurate;
- rebuild the PDF manual;
- generate `app/core-manifest.json`;
- run `quick`, `full`, or `release` audits;
- create a Git commit or tag;
- publish or package the release.

A successful preparation means only that the mechanical release worktree has been initialized.

### 3. Complete release notes and documentation

Replace the `RELEASE_NOTES_TODO` scaffold in `PATCH_NOTES.md` with complete release notes following `PATCH_NOTES_TEMPLATE.md`. The final release audit fails while the scaffold or any `TODO` remains in the target version section.

Review the actual diff and update documentation where behavior changed. At minimum consider:

- `README.md`;
- `ARCHITECTURE.md`;
- `DATABASE.md`;
- `TESTING.md`;
- `CODEMAP.md`;
- `docs/ADMIN_SETTINGS_INVENTORY.md` when Settings ownership changes;
- `docs/PHP_Gallery_Manual.tex` for user/admin-visible behavior.

Do not mechanically rewrite historical version references. For schema changes, describe the new migration and final schema accurately instead of merely replacing the document's current-version marker. For frontend module changes, verify the deployed browser entrypoint or import chain receives the required cache-busting update.

### 4. Build and inspect the manual

After the final manual source edit, rebuild the tracked PDF according to `docs/LATEX_BUILD.md`:

```text
cd docs
pdflatex PHP_Gallery_Manual.tex
makeindex PHP_Gallery_Manual.idx
pdflatex PHP_Gallery_Manual.tex
pdflatex PHP_Gallery_Manual.tex
```

Inspect the resulting `docs/PHP_Gallery_Manual.pdf`, not only the compiler exit code. Verify at least:

- target version and edition date;
- title page;
- table of contents;
- bookmarks/internal links;
- index;
- page breaks and obvious layout damage;
- changed feature sections.

Do not add release-news material to the beginning of the permanent manual. Release history belongs in `PATCH_NOTES.md` unless a deliberate manual appendix is required.

### 5. Run standalone release consistency while editing when useful

The read-only checker can be run at any point:

```text
php scripts/check_release.php
```

or against an explicit target:

```text
php scripts/check_release.php X.Y.Z
```

It verifies the deterministic release invariants without changing files:

- runtime version;
- README current version;
- Testing guide version;
- Database document version;
- Architecture version example;
- manual source version;
- release-metadata entry and `v_<version>` tag value;
- completed patch-note section with no release scaffold/TODO;
- tracked manual PDF not older than its LaTeX source;
- core manifest version.

This checker is a diagnostic convenience. Do not run it redundantly immediately before the release audit unless you are diagnosing a release-consistency failure, because the release audit runs the same checker as a registered suite.

### 6. Generate final integrity data

Only after all source, documentation, release-note, and manual edits are complete, return to the repository root and run:

```text
php scripts/generate_manifest.php
```

Do not edit a manifest-covered source file after this step without regenerating the manifest.

### 7. Run the authoritative release audit

Run exactly one final release profile:

```text
php scripts/audit.php --profile=release
```

Do not precede it with `quick` or `full`. Do not manually replay PHP, Node, WinApp, lint, contract, browser, manifest, or release-consistency checks that the profile already owns.

The release profile currently covers:

- PHP regression suite;
- registered Node regression suite including slow deterministic fixtures;
- WinApp Python regression suite;
- Admin mutation contracts;
- runtime hardening audit;
- complete PHP syntax validation;
- complete JavaScript syntax validation;
- Chromium map integration when the environment safely supports it;
- release consistency;
- core-manifest freshness;
- `git diff --check` when Git checkout metadata is available.

Read the compact console summary first. Open `cache/test-audit/latest.md` only when more detail is needed. Read an individual suite log only for `FAIL`, `BLOCKED`, or a material `SKIP`.

A `PASS` release audit with skipped environment-dependent coverage does not mean "every test passed". Report each material `SKIP` or `BLOCKED` and its reason. Prefer the precise conclusion: "The local release audit passed with the following coverage gaps..." Do not claim that the application is fully functional solely from automated local verification.

If the audit finds a problem, run only the focused command needed to diagnose that reported problem. After the fix, regenerate the manifest if any manifest-covered file changed, then rerun only `--profile=release`.

### 8. Build and inspect the release package

After the release audit is green, create the requested deployment folder or ZIP with the existing deployment helper. The deploy scripts package files only. They do not repair stale release metadata or integrity data.

Inspect the archive listing before publication. Confirm that it contains the intended runtime files and release artifacts, including:

- `app/core-manifest.json`;
- `PATCH_NOTES.md`;
- `release-metadata.json`;
- current migrations;
- current `docs/PHP_Gallery_Manual.pdf` when documentation is part of the package;
- all new runtime scripts/files introduced by the release.

Confirm that excluded local/runtime state remains excluded according to deployment policy, including `config.php`, caches, logs, temporary files, and gallery media when media inclusion is disabled. Repository tests are excluded from production deployment by default.

The following identifiers must agree before publication:

```text
CMS_VERSION
README current version
TESTING/DATABASE/ARCHITECTURE current markers
PATCH_NOTES newest version heading
release-metadata key and v_<version> tag value
manual edition version
core-manifest version
release/archive name
intended Git tag
```

### 9. Commit, tag, and publish only when requested

Release preparation and qualification do not imply authorization to create Git history or publish anything. Agents must not create a release commit, tag, push, GitHub release, or upload unless the user explicitly requests that action.

When explicitly authorized, create the release commit and annotated tag only from the exact qualified tree. The project tag convention is:

```text
v_<version>
```

Do not make additional source edits between the final release audit and the release commit/tag without regenerating the manifest and rerunning the release audit.

### 10. Post-publication smoke test

After publication, exercise an updater upgrade from the previous stable version when practical. Verify at least:

- updater discovery and package integrity;
- migrations;
- Admin login;
- public gallery rendering;
- integrity/status page;
- any release-specific critical workflow.

Record environment-dependent checks that could not be performed locally.

## Release consistency failures

`scripts/check_release.php` is intentionally strict about deterministic mismatches. Typical fixes are:

| Failure | Correct action |
| --- | --- |
| Runtime/document/manual version mismatch | Run or repair `scripts/prepare_release.php <version>` and review the affected marker. |
| Missing release metadata | Run release preparation or repair the target metadata entry. |
| Patch notes incomplete | Replace the generated scaffold/TODO with final notes following `PATCH_NOTES_TEMPLATE.md`. |
| Manual PDF older than `.tex` | Rebuild the manual after the final source edit. |
| Manifest version mismatch | Regenerate `app/core-manifest.json` after all edits. |
| Manifest freshness fails in release audit | A manifest-covered file changed after generation. Regenerate and rerun only the release audit. |

Do not weaken or bypass a consistency invariant merely to make the release audit green. If an invariant becomes obsolete because the release process changes, update the tooling, tests, and this document together.

## Agent efficiency contract

For release work, the intended low-context workflow is:

```text
inspect diff from previous release
php scripts/prepare_release.php <target>
write/review release notes and affected documentation
build and inspect manual
generate manifest
php scripts/audit.php --profile=release
inspect only reported failures/gaps
package and inspect archive
```

Do not enumerate tests, run profiles sequentially, read passing suite logs, or rediscover version-marker locations that `prepare_release.php` and `check_release.php` already own.
