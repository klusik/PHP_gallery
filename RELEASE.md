# PHP Gallery Release Workflow

This document is the authoritative maintainer and agent playbook for preparing and qualifying a PHP Gallery release. `AGENTS.md` points here for release work. The mechanical release scripts intentionally do not replace editorial review, release-note writing, manual inspection, Git review, packaging inspection, or post-publication smoke testing.

## Core rule

Audit profiles are alternatives, not a staircase.

For an actual release, do **not** run `quick`, then `full`, then `release`. Complete the release preparation workflow below and finish with exactly one:

```text
php scripts/audit.php --profile=release
```

The release profile already contains the deterministic coverage from `full`, plus browser integration when available, release consistency, manifest freshness, and Git whitespace validation. Finish every expected edit, generate the manifest, pass the cheap preflight in phase 6, and freeze the release inputs before starting this long audit. If a source or release artifact changes after a successful release audit, regenerate the manifest, repeat the preflight, and rerun only the release profile.

Strict MVC is part of release qualification. The release audit invokes `scripts/check_mvc_boundaries.php` against an intentionally empty baseline. A non-zero MVC finding is a release failure and must be corrected in source; release preparation must not reintroduce legacy baseline debt.

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
6. Choose the final audit path now: direct release profile or the disposable MySQL/Chromium fixture when its prerequisites are available. Run one of them in phase 7, not both.
7. Choose a clean package source now. The deploy scripts walk physical directories and do not exclude every ignored local agent folder. Use a clean checkout or a reviewed staging tree copied from tracked files; stage any new release files before copying. Keep local `.codex/`, `.agent-local/`, and other private/runtime files out of the package source.

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

The first preparation creates a complete `release-metadata.json` entry with a timestamp, readable label, and `v_<version>` tag. With no `--released-at` option it uses the preparation time; a repeat without that option preserves a complete entry. If the intended timestamp differs, set it explicitly before the final manifest and audit.

A successful preparation means only that the mechanical release worktree has been initialized. Check the generated metadata and patch-note scaffold immediately so missing editorial work is visible before qualification.

### 3. Complete release notes and documentation

Replace the `RELEASE_NOTES_TODO` scaffold in `PATCH_NOTES.md` with complete release notes following `PATCH_NOTES_TEMPLATE.md`. The final release audit fails while the scaffold or any `TODO` remains in the target version section.

Review the actual diff and update documentation where behavior changed. At minimum consider:

- `README.md`;
- `ARCHITECTURE.md`;
- `DATABASE.md`;
- `TESTING.md`;
- `CODEMAP.md`;
- `docs/ADMIN_SETTINGS_INVENTORY.md` when Settings or capability ownership changes;
- `docs/PHP_Gallery_Manual.tex` for user/admin-visible behavior.

Remove temporary implementation roadmaps, scratch migration plans, and other explicitly temporary release scaffolding once their permanent architecture/testing documentation has been incorporated. For capability-policy changes, confirm the canonical registry/adapters/routes and Admin Settings discovery documentation agree before release; do not leave a historical stage plan as the only explanation of current behavior.

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

### 5. Generate final integrity data

Only after all source, documentation, release-note, and manual edits are complete, return to the repository root and run:

```text
php scripts/generate_manifest.php
```

Do not edit a manifest-covered source file after this step without regenerating the manifest.

### 6. Pass the cheap preflight and freeze the inputs

Before the long audit, run these read-only checks against the finished tree:

```text
php scripts/check_release.php X.Y.Z
php scripts/generate_manifest.php --check
git diff --check
git diff --cached --check
```

`check_release.php` validates the runtime and documentation versions, the manual source and PDF, complete patch notes, the manifest version, and the release-metadata timestamp, label, and tag. The manifest check validates actual file hashes. Review the pending diff and the chosen package source now: every new release file must be represented, and local ignored state must stay out. Resolve any failure or missing data before proceeding; after a source edit, regenerate the manifest and repeat this cheap gate. A consistency check here is intentional even though the final audit repeats it: it prevents a predictable failure after the expensive suites.

If Git metadata is unavailable, record the missing diff/whitespace coverage. Do not substitute a full test run for these checks. Confirm the selected audit path from phase 1 can run before freezing the inputs.

Initialize the artifact-bound qualification record only after this gate passes:

```text
php scripts/release_qualification.php init X.Y.Z
```

Retain the printed content fingerprint. Changed source, CI inputs, manual source or PDF bytes select a new all-pending record; old approvals remain historical only. See [release qualification evidence](docs/RELEASE_QUALIFICATION.md) for recording explicit reviewer/evidence text and rendering physical PDF pages into ignored cache. Rendering alone never approves a visual check. From here through audit attachment and packaging, treat the release inputs as frozen.

### 7. Run the authoritative release audit

Run exactly one final release profile:

```text
php scripts/audit.php --profile=release
```

Do not precede it with `quick` or `full`. Do not manually replay PHP, Node, WinApp, lint, contract, browser, manifest, or release-consistency checks that the profile already owns.

When the disposable MySQL/Chromium prerequisites in
[GALLERY_WORKFLOWS.md](docs/GALLERY_WORKFLOWS.md) are configured,
`php scripts/gallery_workflow_mysql.php --release` may provision the isolated
fixture around this same single release-profile invocation. This is the
fixture-enabled alternative to the direct command, not an additional audit.
It retains the central reports and refuses skipped mandatory workflow coverage.

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

If the audit finds a problem, run only the focused command needed to diagnose that reported problem. After the fix, regenerate the manifest if any manifest-covered file changed, repeat the cheap preflight and qualification initialization, then rerun only `--profile=release`. Do not rerun it for an unchanged tree merely to chase an environment-dependent skip.

The release report captures source fingerprints before and after its suites. Missing identity blocks qualification; changed inputs invalidate release consistency. Its compact handoff lists outstanding human reviews separately from automated results. Attach the unchanged-tree report without rerunning tests:

```text
php scripts/release_qualification.php record-audit X.Y.Z --fingerprint=HASH
php scripts/release_qualification.php check X.Y.Z
```

Replace HASH with the exact initialized fingerprint. An old/unbound report, a partial suite run, or a full/quick report cannot stand in for release evidence. Complete applicable PDF and browser reviews using the documented `record` command; retain skips and post-publication checks as unresolved. A qualification check returning INCOMPLETE is not permission to publish.

### 8. Build and inspect the release package

After the release audit is green, create the requested deployment folder or ZIP with the existing deployment helper from the clean package source chosen in phase 1. Copy the reviewed, current tracked-file contents into staging if the working directory contains local agent state; do not use a stale `git archive HEAD` that omits uncommitted release edits. The deploy scripts package files only. They do not repair stale release metadata or integrity data.

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

For release work, follow these gates in order. The final audit starts only after the editorial and generated data are complete:

```text
scope and package source; choose direct or fixture-enabled audit
prepare version markers and complete metadata
finish notes, documentation, and manual PDF; inspect the result
generate manifest
cheap gate: check_release, manifest --check, diff/package-input review
initialize qualification fingerprint; freeze inputs
run one release audit path; inspect reported failures/gaps only
attach audit evidence; package from clean source and inspect archive
```

A failed cheap gate returns to editing without spending time on the release suite. A source edit after the frozen point requires a new manifest, preflight, fingerprint, and release audit. Do not enumerate tests, run profiles sequentially, read passing suite logs, or rediscover version-marker locations that `prepare_release.php` and `check_release.php` already own.
