# PHP Gallery Release Workflow

This document is the authoritative maintainer and agent playbook. GitHub Actions
owns normal preparation, qualification, protected merge and immutable tagging.
GitHub Release publication remains a maintainer action.
`AGENTS.md` owns CI-first agent work and working-branch-only writes. Human review
and actual hosting smoke tests remain separate from automated qualification.

## Core rule

Audit profiles are alternatives, not a staircase.

The hosted release job runs one authoritative release profile. Do **not** run
local quick/full/release audits before or after it by default:

```text
php scripts/audit.php --profile=release
```

The release profile already contains the deterministic coverage from `full`, plus browser integration when available, release consistency, manifest freshness, and Git whitespace validation. Finish every expected edit, generate the manifest, pass the cheap preflight in phase 6, and freeze the release inputs before starting this long audit. If a source or release artifact changes after a successful release audit, regenerate the manifest, repeat the preflight, and rerun only the release profile.

Strict MVC is part of release qualification. The release audit invokes `scripts/check_mvc_boundaries.php` against an intentionally empty baseline. A non-zero MVC finding is a release failure and must be corrected in source; release preparation must not reintroduce legacy baseline debt.


## GitHub-hosted release qualification

GitHub-hosted preparation and exact-candidate qualification feed the automatic
reviewed merge/tag lifecycle. One human PR approval is the only normal manual
step after the maintainer pushes the release branch. GitHub Release publication
remains manual; see [the lifecycle contract](docs/RELEASE_LIFECYCLE.md).

`.github/workflows/release-qualification.yml` runs automatically on pushes to `release/v_*`. It validates the branch/version identity, refuses an already existing immutable `v_X.Y.Z` tag, requires the production inventory and integrity manifest to be current, and runs `scripts/check_release.php` before starting expensive jobs. When preflight passes it reuses `.github/workflows/gallery-workflows.yml` with `audit_profile=release`. Existing platform, database, runtime and required-Chromium jobs remain mandatory, and one additional `Authoritative release audit` job runs exactly `php scripts/audit.php --profile=release`. The caller exposes a single `Release qualification gate` for the complete result.

The current GitHub stage now performs deterministic preparation on the release branch itself. It updates the registered version markers and release metadata, aligns all four maintained manual source editions, refreshes the production inventory and integrity manifest, and commits only the approved release-preparation paths back to the same `release/v_X.Y.Z` branch. The write-back job uses a branch-head lease check and refuses to overwrite concurrent maintainer changes.

Editorial release-note prose is generated on GitHub when the target version section is absent or still contains the canonical scaffold. The workflow builds a prompt from the previous stable tag-to-HEAD commit metadata, changed-path inventory, diff stat, complete text diff, `PATCH_NOTES_TEMPLATE.md`, and the previous release section as a style sample. The text diff is retained in full regardless of its byte size; it is never shortened to a prefix or sample. Generated/binary release artifacts remain excluded from that text diff, and repository evidence is explicitly treated as untrusted quoted data so source comments or strings cannot become model instructions.

Git stdout and diagnostics use separate automatically removed temporary files, so collecting a large diff cannot deadlock on full process pipes. Each Git command has a 30-second deadline; the complete evidence step has a separate two-minute workflow limit. The collector stops its owned child on timeout or refusal and reports the complete prompt byte count on success. Existing metadata/template limits and the 8 KiB diagnostic limit still reject oversized input explicitly, without passing partial evidence or raw diagnostics to Copilot. A provider rejection or invalid model output blocks qualification; it does not trigger diff truncation. Completed maintainer-authored notes remain the supported way to skip AI generation.

When Copilot cannot accept the complete comparison in one request, `.github/scripts/patch_notes_ai_chunks.php` processes the same **complete** prompt through a bounded map/reduce sequence. The original diff is partitioned into contiguous, non-overlapping UTF-8-safe slices of at most **100,000 bytes**, including very long individual source lines. Every original diff byte is supplied exactly once to a first-level Copilot request. A SHA-256 manifest records the complete source prompt, unchanged comparison metadata, full diff, precise slice offsets and generated input hashes. Each first-level request must produce a nonempty, UTF-8-valid factual digest of at most **6,500 bytes**; a missing, oversized or altered digest blocks the release. Individual requests have a 240-second deadline and the overall AI step is bounded by the GitHub job timeout.

All accepted digests are supplied to the final synthesis together with the **unchanged** original commit metadata, changed-path list, diff stat, template and previous release style. Its input is limited to **180,000 bytes**. If all digests do not fit, the helper builds another complete level of smaller Copilot reduction requests and repeats until they fit; it never drops tail slices or shortens the original diff. Each input/output is checked before the next level. Model digests are untrusted, so release-note correctness still depends on editorial review and the existing strict final Markdown validator. Copilot model selection tries `gpt-6-luna` once and switches to `auto` for the remaining requests only after the explicit unavailable-model response. A model failure, refused context or invalid result fails preparation rather than silently proceeding with incomplete notes. The chunking and reduction tests use synthetic model digests and do not consume Copilot requests.

For this user-owned repository the Copilot CLI authenticates with the repository secret `COPILOT_GITHUB_TOKEN`, containing a fine-grained personal access token with the account-level **Copilot Requests** permission. The workflow requests `gpt-6-luna`, falls back to Copilot `auto` only for the explicit model-unavailable response, disables project prompt-mode extensions, grants no Copilot tools, and uses non-interactive mode. The model returns text only and cannot directly modify the checkout. `.github/scripts/patch_notes_ai.php` validates the exact target heading, one-version-only structure, required release sections, output bound, absence of placeholders/code fences/meta-commentary, and then replaces only an incomplete target section. A completed maintainer-authored target section skips the AI steps entirely and is preserved.

After validated release notes exist, the workflow builds all four PDFs on GitHub, refreshes integrity data, runs `check_release.php`, commits the tracked release artifacts, and qualifies that exact prepared SHA. If the Copilot secret is unavailable, the AI request fails, or model output does not satisfy the validator, qualification is blocked rather than accepting partial notes. The maintainer can still complete the section manually and push it; the next run detects completed notes and does not call Copilot. A workflow-created commit uses the repository `GITHUB_TOKEN`; GitHub intentionally does not start another push workflow for that commit, so qualification continues inside the same run against the emitted prepared SHA.

Generation and validation share the required main headings: `### Highlights`, `### Technical Details`, and `### User Impact`. All three are mandatory even for releases limited to tests, documentation, or tooling; only irrelevant `####` subsections may be omitted. The impact section must describe evidenced maintainer/administrator/visitor consequences or explicitly state that public or administrator behavior did not change. It must not invent a product improvement to fill the template. Validation requires real third-level heading lines; an inline mention, a renamed heading or a fourth-level heading does not satisfy the contract.

If AI generation or final validation fails, the workflow retains the available raw `release-notes-response.md` in the `release-notes-rejected-response` Actions artifact for seven days. The artifact contains only model prose, not evidence prompts, Copilot authentication diagnostics or tokens. Review it together with the exact validation error in the failed step. Invalid output still blocks preparation and is never applied to `PATCH_NOTES.md`; completed maintainer-authored notes continue to bypass AI generation.

### Toolchain and fail-fast ordering

Before deterministic preparation, Copilot or TeX, GitHub invokes
`php scripts/audit.php --profile=release-preflight`. Its centrally registered
static suites cover complete PHP syntax, changed declaration/policy documentation,
source-contract inventory, the whole-tree Python import policy and the existing
CI workflow contract. This early profile is an optimization; the complete
matrix and final authoritative release profile still qualify the exact prepared SHA.

Manuals use checksum-locked TinyTeX-1 2026.02 (TeX Live 2025) with the archived
[TeX Live 2025 final repository](https://ftp.math.utah.edu/pub/tex/historic/systems/texlive/2025/tlnet-final/).
The October 2026 bundle against a rolling repository allowed package-manager drift;
the selected February bundle already has the final repository's `texlive.infra`
revision 76780. `.github/texlive-lock.json` pins the bundle URL/SHA256 and frozen
repository database SHA256. `.github/texlive-packages.txt` records verified
TeX Live package names, including Czech, German and Swedish language/hyphenation support.
Base packages are aligned to that same frozen repository before explicit installation.
There is no live-repository fallback or automatic `tlmgr` self-update.
Updating the toolchain requires a reviewed lock change and fresh qualification.

The cache holds `~/.TinyTeX`. Its exact `tinytex-v2-ubuntu24.04-x86_64-` key
hashes the lock, package manifest and provisioning helper. No restore prefix is used.
A miss downloads/verifies the bundle and repository metadata, then installs against
the frozen repository. Downloads have a 20-second connect timeout, 180-second
attempt limit, two retries and a 240-second retry budget; the complete bundle step
is limited to 300 seconds and frozen package provisioning to 600 seconds.
A hit skips both network steps, verifies provenance, local package inventory,
required resources and executable availability, then activates the same binary path.
Corrupt/stale cache data fails closed; invalidate the key rather than silently
repairing an existing cache entry. Cache save uses the restored primary key and
requires successful completion of all four manuals.

All editions retain `pdflatex -> makeindex -> pdflatex -> pdflatex`.
`SOURCE_DATE_EPOCH` comes from the stable release timestamp, making PDF timestamps
repeatable across cache miss/hit and reruns. The manifest generator uses the same
validated epoch for its generation timestamp; an already-current manifest is preserved. All PDFs are rebuilt on each preparation;
no unchanged-document shortcut may accept PDFs with stale version/date markers.
An identical prepared tree already at the branch head may be reused on a rerun
without a write. A differing tree still fails the write-back lease.
The final gate checks that the release branch still points to the qualified candidate.

### Maintainer release steps

1. Create `release/v_X.Y[.Z]` directly from current `develop` and push it.
2. Wait for automatic preparation, all four PDFs and exact-Q full CI. Approve the
   automatically created release PR to `main` once.
3. Automation performs the protected standard merge, immutable tag and safe
   develop fast-forward. Create/publish the GitHub Release manually using the
   workflow's `manual-release-assets`, completion `release-evidence.json`/SHA-256, and any
   required `manual-winapp-installer`.

No normal step requests SHA/run IDs, a workflow dispatch or deployment approval.
A changed head needs fresh qualification. Missing/red mandatory checks or unreadable effective
policy are BLOCKED. Omitted bypass membership is explicitly recorded and remains
server enforced; it is never replaced with a fabricated empty list. GitHub Release publication is never automatic.

The complete parent/tree, token-event, retry and synchronization contract is in
[docs/RELEASE_LIFECYCLE.md](docs/RELEASE_LIFECYCLE.md). Main requires one human
approval and merge commits; develop requires linear non-force FF and exact CI.
The installed controls are inspected, never changed by the implementation.
Origin initialization and preparation write only the corresponding release branch.
Qualification automatically dispatches completion at Q before the workflow reaches
default main. The observer waits for the single owner approval and independently verifies `parents(M)=[P,Q]` and
`tree(M)=tree(Q)` before tagging M. Parallel develop work is retained and reported
as blocked reconciliation without undoing the correct tag.

Release qualification uses the previous release-side Q as its comparison base,
independent of whether the tagged previous main merge is an ancestor. Published
historical evidence remains supported. A newer tagged predecessor can also be
validated through exact hosted qualification and its reviewed standard merge,
even before manual GitHub Release publication. Unavailable evidence stays BLOCKED.

## Default artifact policy

Normal release preparation does not create a deployment folder, ZIP, packaging staging tree, checksum file or handoff bundle in `deploy/`. Packaging phases below apply only when the user explicitly requests a specific package/archive. Audit and qualification evidence stay in the existing ignored `cache/` locations.

Rebuild the Windows installer in `winapp/dist/` only when shipped companion code, assets, dependencies, runtime binaries or build/installer behavior changed since its previous build. CMS metadata, documentation and test-only fixes do not trigger an installer rebuild. Keep its independent version unless the user requests a change; otherwise leave `winapp/dist/` untouched.

## Local fallback/recovery phases

The commands below document the retained maintainer recovery path. They are used
only for an explicitly requested local operation or genuinely unavailable hosted
CI, not as routine agent steps. Local PASS does not establish hosted qualification;
report **BLOCKED / not CI-qualified** until the required hosted run is available.

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
7. Only when packaging is explicitly requested, review the canonical positive inventory in `app/production-files.json`. Both deploy helpers consume that same exact file set; unrelated workspace files cannot enter a production artifact. New shipped paths require an explicit inventory refresh and review, followed by manifest generation. See [production file policy](docs/PRODUCTION_FILES.md).

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

The release scripts never compose missing TeX manual prose. They update edition markers, may create release notes, and compile PDFs from existing sources. Every material feature, bug fix, runtime/CI/developer workflow change, and relevant documentation change should already have updated the appropriate sections in all four TeX source editions during ordinary development. Before release qualification, review the changed-scope documentation for completeness, including maintainer-facing topics; correct any omissions in the release branch before its final generated artifacts and audit. PDF build success and release version consistency are not evidence of up-to-date explanations.

### 4. Build the manuals and check compilation

During ordinary development, maintain the Markdown/LaTeX sources and keep all four manual source editions aligned without rebuilding PDFs after each change. Tracked PDFs may lag behind draft sources until this final release phase; routine documentation edits do not require a local TeX installation.

After all release source, documentation and metadata edits are complete, build all four tracked PDFs together once before the final integrity manifest and exact-candidate qualification. The GitHub-hosted release preparation workflow owns this batch build. For an explicitly requested local release build or diagnosis of a concrete compiler/layout failure, use `docs/LATEX_BUILD.md`:

```text
cd docs
pdflatex PHP_Gallery_Manual.tex
makeindex PHP_Gallery_Manual.idx
pdflatex PHP_Gallery_Manual.tex
pdflatex PHP_Gallery_Manual.tex
```

Apply this build to all four maintained editions (English, Czech, German and Swedish). Check the source version/date, successful compiler exit status and relevant warnings, including unresolved references/index entries or reported layout problems. Existing release consistency checks remain required.

Do not routinely read the resulting PDFs, extract their text, render pages, take screenshots or create contact sheets. Visual PDF review is performed only on explicit user request or to diagnose a concrete build/layout problem, and should then cover only affected pages. A routine successful LaTeX rebuild does not require human PDF visual approval.

Do not add release-news material to the beginning of the permanent manual. Release history belongs in `PATCH_NOTES.md` unless a deliberate manual appendix is required.

### 5. Generate final integrity data

Only after all source, documentation, release-note, and manual edits are complete, return to the repository root and run:

```text
php scripts/generate_manifest.php
```

When shipped paths were added or removed, first stage the reviewed source paths and run `php scripts/generate_production_files.php`. Review its inventory diff before generating the integrity manifest. Inventory regeneration is a developer operation; packaging and public requests never discover new production membership.

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

Retain the printed content fingerprint. Changed source, CI inputs, manual source or PDF bytes select a new all-pending record; old approvals remain historical only. See [release qualification evidence](docs/RELEASE_QUALIFICATION.md) for recording explicit reviewer/evidence text. Its PDF-rendering workflow is optional and applies only when explicitly requested or needed to diagnose a concrete problem. From here through audit attachment and packaging, treat the release inputs as frozen.

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

Replace HASH with the exact initialized fingerprint. An old/unbound report, a partial suite run, or a full/quick report cannot stand in for release evidence. Complete applicable browser reviews and any explicitly requested PDF review using the documented `record` command; retain skips and post-publication checks as unresolved. Optional PDF review fields may remain pending; do not fabricate visual approvals or make those fields an additional routine preparation gate. A qualification check returning INCOMPLETE is not permission to publish.

### 8. Build and inspect an explicitly requested release package

Skip this phase during normal release preparation. Only when the user explicitly requests packaging and the release audit is green, create the requested deployment folder or ZIP with the existing deployment helper. The helper requires a current manifest, copies only canonical inventory members from the current source bytes, and verifies the exact staged file set. Missing required files and unexpected staged files fail packaging. An existing output must be reviewed and moved aside by its owner before another package is built; helpers do not erase it. Packaging does not refresh the inventory or repair release metadata.

Inspect the archive listing before publication. Confirm that it contains the intended runtime files and release artifacts, including:

- `app/core-manifest.json`;
- `PATCH_NOTES.md`;
- `release-metadata.json`;
- current migrations;
- current `docs/PHP_Gallery_Manual.pdf` when documentation is part of the package;
- all new runtime scripts/files introduced by the release.

Confirm that staged files agree with the positive inventory using `php scripts/release_files.php verify --root=<stage> --source-root=<source> --profile=production`. Installation-owned `config.php`, active custom CSS, caches, logs, temporary payloads, local agent state and gallery media are outside production membership. Runtime directory protection files are explicit members. Media is a separate opt-in; repository tests may be included only in an explicit local source-review profile, never FTP deployment.

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

## Recovery efficiency contract

For release work, follow these gates in order. The final audit starts only after the editorial and generated data are complete:

```text
scope; choose direct or fixture-enabled audit; package source only if explicitly requested
prepare version markers and complete metadata
finish notes and documentation; build all four manual PDFs and check compiler results/warnings
generate manifest
cheap gate: check_release, manifest --check, diff/package-input review
initialize qualification fingerprint; freeze inputs
run one release audit path; inspect reported failures/gaps only
attach audit evidence; package and inspect only if explicitly requested
```

A failed cheap gate returns to editing without spending time on the release suite. A source edit after the frozen point requires a new manifest, preflight, fingerprint, and release audit. Do not enumerate tests, run profiles sequentially, read passing suite logs, or rediscover version-marker locations that `prepare_release.php` and `check_release.php` already own.


### Fail-fast hosted release preparation and first-trigger timestamp (#101, #139, #140)

The hosted `release-qualification.yml` prepare job uses separate, ordered tiers:
`release-preflight` (Stage A, cheap syntax/source policy/contract-budget/workflow checks),
`release-stage-b` (Stage B, centrally registered MVC-boundary and Admin-mutation
contracts), then editorial/Copilot, locked TinyTeX, four-language PDF compilation,
metadata and manifest. Both tiers must pass before generation. A failed preflight
retains short-lived `release-source-preflight` JSON/Markdown and failing-suite
reports bound to the checkout source identity and comparison base; a source-stage
PASS is **not** final release qualification. Stage D still qualifies the exact
prepared SHA with every mandatory hosted job and the final release audit.

For a target version absent from `release-metadata.json`, the first hosted run binds
its display date to the immutable triggering source commit's Unix timestamp
(`git show -s --format=%ct "$GITHUB_SHA"`) supplied as `RELEASE_INITIAL_EPOCH`.
A rerun from the same checkout yields the same initial date and metadata bytes.
Once the entry exists, its strictly validated `released_at`, `released_label`
and `v_<version>` tag take precedence even when the workflow is retriggered
from the bot-prepared commit. A missing initial epoch or mismatched target entry
fails closed. This timestamp is **not** the later actual GitHub publication time;
it is the deterministic preparation source date used by the four manuals and
`SOURCE_DATE_EPOCH`. Existing published metadata is never rewritten.


## Operational acceptance

Central source CI and disposable Git fixtures do not prove real GitHub PR routing,
required-check eligibility or protected token writes. Candidate preparation retains
actual workflow-token policy visibility in `live-release-readiness`. Missing bypass
inventory is a concrete blocker; do not weaken the policy checker or introduce a
privileged integration silently. See the isolated E2E acceptance plan in
[docs/RELEASE_LIFECYCLE.md](docs/RELEASE_LIFECYCLE.md).

Existing v_0.126 is immutable and already published. Do not create v_0.126.1's
release branch until this feature is qualified, integrated and operational
acceptance is explicitly authorized. Hotfix cleanup is PENDING until transfer,
final hosted CI and durable feature preservation are proven.
