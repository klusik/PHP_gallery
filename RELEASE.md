# PHP Gallery Release Workflow

This document is the authoritative maintainer and agent playbook. GitHub Actions
owns normal preparation, qualification, protected promotion and publication.
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

Issue [#100](https://github.com/klusik/PHP_gallery/issues/100) provides GitHub-hosted
preparation, exact-candidate qualification and a separately authorized protected
promotion/publication path. No long local audit is part of the normal process.

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

### Branch lifecycle

The canonical lifecycle from [#101](https://github.com/klusik/PHP_gallery/issues/101)
is `develop -> release/v_X.Y.Z -> main -> develop`. The active release line owns
release-critical fixes and is not routinely rebased onto newer develop work.
A qualified candidate is never rebased. Any candidate mutation needs fresh
qualification; future promotion must originate from that exact release candidate,
followed by owner-approved SHA-preserving linear reconciliation into develop.
Do not duplicate a release fix independently on develop. The current v_0.122
bootstrap divergence is a documented exception. Reconciliation remains a separate
maintainer operation after publication; agents do not directly update develop/main.

Preparation and qualification alone do not request publication. The protected
promotion workflow below owns the separately approved release actions.

The workflow also supports `workflow_dispatch` for explicit reruns. GitHub only exposes manual dispatch for workflow files present on the repository default branch. During the bootstrap release that first carries this workflow from `develop` to `main`, create/push the prepared `release/v_X.Y.Z` branch and let the push trigger run it automatically. After the workflow exists on `main`, later releases can also use **Actions > Release qualification > Run workflow**, select the release branch, and optionally supply the version as an additional cross-check.

A successful GitHub gate is automated qualification evidence only. Required human/manual acceptance remains separate, and a changed release candidate SHA must be qualified again.

## Protected promotion and publication

For the already merged v_0.126 only, follow the separately owner-approved
[publication recovery runbook](docs/RELEASE_PUBLICATION_RECOVERY_v_0.126.md).
Its pinned recovery ref uses this existing workflow and publisher; no additional
main commit, release PR, tag replacement or ruleset change is authorized.

The single-owner normal lifecycle is:

1. Initialize `release/v_X.Y[.Z]` from an immutable selected `develop`
   SHA, then qualify the prepared exact SHA using Stage A, Stage B and full
   Stage D GitHub Actions. The qualification workflow creates the
   SHA-bound `Release qualification` check and durable attempt evidence.
2. Dispatch **Protected release promotion** from trusted `main` in
   read-only `plan`. Verify release branch/head, run/attempt, complete
   mandatory CI, immutable origin and exact published predecessor identities.
3. Dispatch `promote` and manually approve its separate
   `release-promotion` environment. The owner-verified write job
   checks active `main` protections and creates or reuses a bot-authored
   exact-SHA release PR. It **does not enable auto-merge, approve or
   merge the PR**.
4. On the PR, `klusik` selects **Approve workflows to run** if GitHub
   requests it, checks every mandatory PR status and submits a distinct
   PR **Approve** review. The owner manually selects **Create a merge
   commit**. A changed head/base requires requalification.
5. Dispatch `publish` separately and approve `release-promotion`
   again. Build and validate the canonical package and required Windows
   companion in read-only jobs, never exposing write credentials to
   candidate code.
6. The publisher verifies the real bot PR, effective owner approval
   tied to the exact SHA, merged `main` SHA/tree, immutable candidate
   and successful CI, then creates a non-moving tag, checked draft
   release/assets, durable audit and finally makes the draft public.
7. The owner separately approves linear reconciliation. If develop is unchanged,
   perform an actual non-force FF to exact Q. Otherwise prepare a single-parent L
   over the new develop head, prove the entire three-way content and all parallel
   history, qualify exact L, and let the owner FF. No mandatory develop PR or new
   develop merge is permitted. Record immutable owner/CI/patch/tree evidence as
   `RECONCILED_FF` or `RECONCILED_EQUIVALENT`. Retirement remains separately approved.

Deployment approval, PR CI-run approval, PR review and merge are
**distinct events**. A `manual_review` input supplies audit context,
not proof that the PR was approved on GitHub. The workflow is deployed
to default `main` only through an owner-reviewed release merge; ordinary
feature-branch agents cannot perform that integration.

### Required server setup and least privilege

As of 2026-10-09, the owner has staged rulesets `24808772` for
`main` and `24808873` for `develop`, both **DISABLED**.
Do not activate them during implementation. Before any separately
 authorized activation, verify the branch-specific policies. Main requires one
owner PR approval, stale-review dismissal, resolved conversations, merge commits,
no force/deletion/bypass and **loose** `Release qualification`. Develop requires
linear history, no force/deletion/bypass, **no mandatory PR**, and strict
`Complete required CI matrix`. Bind both contexts to GitHub Actions integration
15368. Rebase-and-merge is not a true SHA-preserving FF. Actual PR head/test-merge
status eligibility and the owner FF must be tested live; unknown compatibility
remains BLOCKED. This implementation does not change either ruleset.

The separately configured environments `release-promotion`,
`release-reconciliation` and `release-retirement` each require the
GitHub User `klusik` as sole reviewer with **Prevent self-review OFF**,
**admin bypass OFF** and exactly one custom deployment branch `main`.
The repository Actions default stays read-only; only approved writer
jobs obtain scoped `GITHUB_TOKEN` permissions, while preparation,
package and verification jobs stay least-privileged. Enable Actions
PR creation, not automated PR approval. No alternate release PAT, App
token or branch-protection bypass is authorized for the normal path.

A PR created by `GITHUB_TOKEN` is authored by
`github-actions[bot]`, distinct from owner `klusik`.
GitHub may require `klusik` to explicitly choose
**Approve workflows to run** before PR CI starts. The owner must then
submit an actual PR review and manually merge. These requirements
and the safe activation gate are detailed in
[docs/RELEASE_LIFECYCLE.md](docs/RELEASE_LIFECYCLE.md).

This model provides deliberate owner-only decisions and an auditable
bot/owner separation, **not independent two-person review**. A
compromised owner account remains a residual risk. Both rulesets
must stay inactive until the updated default-branch workflows, actual
review identities and required checks have been validated.

The GitHub REST API may omit the branch-policy `type` from
the deployment-policy listing and may redact ruleset
`bypass_actors` from non-administrative readers. The
owner must inspect the branch-only policy in Settings; if
the approved, narrowly-scoped `GITHUB_TOKEN` cannot
read the bypass inventory, the authorization code fails
closed rather than presuming zero bypass actors. Supplying
separately authorized policy evidence is an outstanding
operational acceptance step, not permission to give
untrusted jobs an administrator token.

### Red-check handling in owner-only mode

The historical red-check exception path is now **disabled for write
modes**. `promote` or `publish` with `override_ack=true` fails
closed. An incomplete, failed, stale, cancelled or skipped mandatory
job must be repaired and the candidate fully requalified; no checks
are relabeled green. Read-only `plan` retains original failure
evidence and human diagnostic context without merge authority.

### Durable evidence, retries and stale runs

Public assets include `production.zip`, manifest and inventory, release metadata,
notes, SHA-256 checksums, complete `qualification-evidence.zip`, final integrity
record, and `release-evidence.json` containing candidate/main/tree identity,
qualification run/attempt, base, dispatch actor, bot PR URL/number, exact owner
approval ID/reviewed SHA/time, manual review and original CI outcomes.
Required rebuilt WinApp installer/update metadata are attached separately. These
GitHub Release assets survive expiration of ordinary Actions artifacts. Evidence
archives include all uploaded audit/source/browser/database reports and detailed
failure logs available from the qualification run, including red evidence.

Per-branch/version concurrency serializes write actions without cancelling an
in-flight upload. Every write checks fresh refs after approval; a new candidate
needs a fresh run. Existing PRs are reused only for the exact head. A tag is never
moved: an existing different target is a hard collision. Interrupted uploads leave
a draft; retries accept identical existing assets by size/digest, refuse conflicting
assets and never use clobber. An already published matching release is checked
without replacement. Fixes to frozen inputs require fresh preparation/qualification.

No production/WEDOS credentials or live installation are used. Post-publication
repository tag/release/asset checks are automated; updater/hosting/browser acceptance
against a real installation remains pending until separately performed.

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


## Guarded GitHub release lifecycle and reconciliation (#101)

The full maintained runbook is [docs/RELEASE_LIFECYCLE.md](docs/RELEASE_LIFECYCLE.md).
It defines the explicit immutable `develop` origin record required **before**
release stabilization, protected `main` promotion and deterministic tagged
publication, then separately approved linear reconciliation into develop.

**A published release is not a reconciled release.** The operator must check
single-owner release PR approval, ordered main merge parents, complete linear
reconciliation content/history proof and exact Q/L full hosted CI. `SYNC_PENDING` or `SYNC_BLOCKED` is neither `RECONCILED_FF` nor
`RECONCILED_EQUIVALENT`; branch retirement
is optional and requires a separately reviewed exact Git ref lease. A change to
`main` during stabilization is `BLOCKED_MAIN_ADVANCED` and requires a new
reviewed candidate. No automated rebase, force update, bypassed review, tag
rewrite, or unconditional direct protected-branch merge is permitted.

The workflows `release-reconciliation.yml` and `release-retirement.yml` have
read-only plans and owner-approved, environment-protected propose/delete jobs. Effective GitHub
branch rulesets and environments **must be installed and verified on the live
repository by an authorized maintainer**; source-control example JSON does not
configure permissions. Until that evidence exists, publication, reconciliation
writes and branch cleanup remain operationally **BLOCKED**.

### Linear identities, next-release initialization and the first bootstrap

Follow [the canonical linear runbook](docs/RELEASE_LIFECYCLE.md). The previous
annotated/lightweight tag object, published main P, release-side Qprev and tree
are separate identities. Qualification, audit and commit notes explicitly use
Qprev; file/WinApp scopes compare endpoint trees. Never require P ancestry in Q.
The develop FF verifier independently rejects merge commits in its new commit range.
The new main merge must have parents [P,Q] and exactly Q's tree. Immutable schema
v1 origins remain supported. The existing v_0.126 origin is unchanged; separately
pinned legacy v_0.125 identities carry UNKNOWN_LEGACY qualification, not a fake pass.

Start New Release will atomically create future origin commits and supplemental
predecessor records, verify owner/source/previous tag and fresh refs, and dispatch
existing qualification. It excludes the already initialized v_0.126. The workflow
becomes available on main through the first release merge, without an intermediate
untagged feature commit.
GitHub cannot atomically lease several refs. Fresh checks before ref creation and
qualification handoff block concurrent tag/source changes; a race detected after
creation retains the immutable origin for explicit owner recovery.

GitHub excludes workflow-dispatch job checks from required PR checks. Automatic
dispatch preparation remains useful, but promotion fails closed with
`BLOCKED_PR_CHECK_ELIGIBILITY` until the owner pushes a reviewed finalization
commit and full push-triggered qualification verifies its new exact SHA. An
eligible-event or external-App bridge needs a separate live acceptance decision.
The existing disabled rulesets also omit required-check `integration_id` in REST;
protected write guards require explicit GitHub Actions provenance. No setting is
changed by this implementation. See the canonical runbook for evidence and recovery.

For that first release, the already-present release-promotion workflow filename
supports owner-dispatched `bootstrap` **from exact qualified v_0.126 ref**. Its
isolated job can only create a bot PR (`contents: read`, `pull-requests: write`).
Review the candidate workflow first. The owner then separately authorizes PR CI,
reviews Q and manually creates the ordinary merge. New publisher code is now on
main, before a separately approved publish. GitHub ref dispatch, required-check
eligibility and active server protections must be demonstrated live; unsupported
routing is BLOCKED. Never use the older auto-merge/red-override publisher.

Owner reconciliation acceptance records every conflict decision against exact
base/develop/release/result blob and mode, full expected/result trees, SHA-256 of
binary/full-index release and replay patches, exact CI and a completed owner run.
Only centrally verified generated modules/inventory/manifest may differ from the
computed merge. A conflict, lost change, wrong parent/tree, missing CI or raced
head remains pending/blocked. Automation never pushes develop. Keeping the release
branch after completed reconciliation is valid.
