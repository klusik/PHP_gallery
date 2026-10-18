# PHP Gallery Release Workflow

The release starts from an already qualified commit on protected `develop`.
**Integrate into develop** owns the strict complete source qualification.
Release preparation assembles that source; it does not run another BASIC/FULL
audit, compare test engines or reopen historical qualification archives.

## Integrate source first

Finish source, tests and permanent documentation, including all four TeX source
editions. Push the authorized feature and obtain BASIC. In GitHub Actions
**Integrate into develop → Run workflow**, select main or the intended feature
workflow ref and its source branch. Changed feature tests/CI execute directly.
FULL must pass on the exact final one-commit candidate before guarded develop FF.

Successful execution is retained before publication. `qualify_only` leaves
develop unchanged; `qualified_run` publishes an identical sealed candidate/base
without another FULL. Source changes require new execution. Failed mandatory
tests must be repaired, not bypassed. See the
[CI contract](docs/CI_COVERAGE_REUSE_CONTRACT.md).

For an explicitly authorized CI-only installation ahead of a release, base the
feature on main and dispatch **Basic source checks** with `install_ci`.
Its complete FULL and release audit produce the bot PR under normal review
protections. This installs infrastructure, not a production version.

## Prepare the release

In GitHub Actions, select **Begin Release**, run it from `develop` and enter the
version including `v_` (for example `v_0.136.2`). It creates
`release/v_0.136.2` at the exact `develop` commit selected for that run. The
workflow only creates the branch; it does not qualify or publish the release.
The automated release workflow:

1. Freezes A and the actual main/release heads and resolves the existing stable
   tag through protected main merge history. Existing tags/origins stay immutable.
2. Updates version/date metadata and aligns all four maintained manual editions.
3. Preserves completed editorial patch notes or generates their missing prose
   from complete Git evidence using the existing bounded Copilot workflow.
4. Compiles all four PDFs with the locked TinyTeX/package inputs and reproducible
   release timestamp. Compiler/build errors remain assembly failures.
5. Generates production inventory and the complete-file integrity manifest last.
   Build the WinApp installer only when shipped companion inputs changed;
   otherwise preserve its independent version and verified existing assets.
6. Commits generated release inputs to the same release branch using a checked
   head lease. It does not repeat source qualification.
7. Retains lifecycle-schema-4 assembly metadata and prepares complete attachments,
   then opens the exact bot-authored PR to main.

Patch-note generation keeps the complete source diff, uses bounded UTF-8 slices
and SHA-256 manifests where needed, and validates editorial headings/prose.
Missing responses or invalid prose block assembly; they never truncate evidence.
A completed maintainer-written version section skips generation. Credentials and
prompts are excluded from diagnostic attachment uploads.

## Review, tag and publish

Approve the bot PR once. Automation performs the protected standard merge,
verifies its actual parents/tree, creates the immutable version tag, fast-forwards
unchanged develop to that same main commit, and fills an unpublished GitHub Release
draft. No separate synchronization commit is created. Main history is linked before
PR review; early approval is observed after assembly. Concurrent develop work
is preserved and reported separately.

Wait for **DRAFT_READY**, then review notes and all original attachments and
manually choose **Publish release**. A green assembly is not publication approval.
There is no normal manual ZIP extraction, artifact copying or upload step.

After the complete draft and completion artifact are retained, a final job
automatically deletes only the exact `release/v_X.Y[.Z]` ref at reviewed Q.
It rechecks original asset sizes/digests, merge/tag identity, main/develop ancestry,
open PR dependencies and other active release work, then uses a server-side SHA
lease. Missing synchronization keeps the branch. A moved ref or failed proof
blocks cleanup while preserving the ready draft. Retry only the failed cleanup
job; it uses the retained artifact and immutable Q even if the branch was already
deleted after a lost response. Publication stays manual and needs no release ref.
Completion executes Q's workflow, so this behavior starts with the first release
containing it in qualified develop, without a preliminary main installation.

On **TAGGED / DRAFT_PENDING**, resume completion for the same Q: retain M/tag,
reconcile any lost response against the complete server inventory, and add only
missing verified assets. Conflicting or already published assets are not replaced.
Parallel develop work blocks unsafe synchronization, never a correct tag/draft.

## Artifacts and exceptional diagnosis

Do not create deployment directories, ZIPs or installers during ordinary source
work unless explicitly requested. Hosted release assembly owns the production ZIP,
all four manual PDFs, manifest/inventory/version metadata, checksums, release notes
and permanent assembly/completion receipts. Ignored cache holds diagnostics.

Local audits/builds are exceptional diagnosis or explicitly requested recovery,
not a routine release staircase. Do not repeat green hosted source qualification.
For an observed assembly failure inspect its compact summary and failed owner's
referenced diagnostics, repair the cause and resume only the necessary stage.

Preserve stable tags, published assets, old origins and original evidence.
Never directly update protected main/develop or weaken server protections.
Human browser/hosting smoke checks remain distinct from assembly. Historical
release refs retain the separately approved retirement workflow; automatic cleanup
only targets the current ready schema-4 draft. See the
[lifecycle](docs/RELEASE_LIFECYCLE.md) and
[maintainer checklist](docs/MAINTAINER_RELEASE_CHECKLIST.md).

The reviewed develop history policy must admit main's release merges while
retaining non-force updates, deletion protection, exact Actions checks and no
bypass. The example ruleset describes that target configuration; code never edits
live protections. A remaining `required_linear_history` rule blocks the release
PR with an explicit diagnostic. Ordinary feature integration still requires one
qualified commit directly above develop.

Existing published content-copy synchronization commits remain historical data.
The next release can retain them through its selected source and then join both
branches by a normal FF. Immediate realignment of already diverged refs needs a
separately reviewed migration; release completion never resets or rebases them.
