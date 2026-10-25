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
FULL must pass on the exact final one-commit candidate before guarded develop
FF.

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

In GitHub Actions, select **Begin Release** and enter the version including
`v_` (for example `v_0.136.2`). Select a branch containing the intended workflow
revision; `develop` includes newly integrated release changes before main does.
The workflow reads current protected `develop` independently of that selector,
freezes its exact SHA and creates `release/v_0.136.2` there. It explicitly
dispatches **Release qualification** on the release ref, using that source's
workflow/scripts. Token-created branch events are not a continuation mechanism.
No preliminary release with old CI or extra BASIC/FULL is needed.

The dispatch binds frozen source, initiating run and attempt. Initialization
authenticates the original maintainer through server-owned Begin Release
metadata, including its version-specific run title; a bot actor alone grants no
authority. Ordinary external-note continuation still requires an existing origin.
Retries reuse an unchanged source ref and avoid dispatching a duplicate active
assembly. A moved release ref is preserved: continue its existing assembly run
instead of restarting Begin Release. Failed creation/dispatch is reconciled by
readback on the next invocation, never by overwriting an existing release.
The automated release workflow:

1. Freezes A and the actual main/release heads and resolves the existing stable
   tag through protected main merge history. Existing tags/origins stay
   immutable.
2. Updates version/date metadata and aligns all four maintained manual editions.
3. Preserves completed editorial patch notes. Small changes use bounded Copilot
   generation; large changes or unavailable Copilot export an external AI
   handoff and wait for source-bound editorial notes before proceeding.
4. Compiles all four PDFs with the locked TinyTeX/package inputs and
   reproducible
   release timestamp. Compiler/build errors remain assembly failures.
5. Generates production inventory and the complete-file integrity manifest last.
   Build the WinApp installer only when shipped companion inputs changed;
   otherwise preserve its independent version and verified existing assets.
6. Commits generated release inputs to the same release branch using a checked
   head lease. It does not repeat source qualification.
7. Retains lifecycle-schema-4 assembly metadata and prepares complete
   attachments,
   then opens the exact bot-authored PR to main.

Patch-note routing measures the complete source diff before installing or calling
Copilot. Automatic mode admits at most 100000 diff bytes, 20 changed source files
and 180000 complete prompt bytes: one evidence request plus final synthesis.
Larger changes, missing credentials or explicit `notes_mode: external` spend no
Copilot requests. Installation, provider, quota, timeout and rejected-prose
failures switch to external handoff. No billing API or extra billing permission
is required; a configured token does not prove remaining allowance.

**NOTES_PENDING** is a successful handoff, not successful release qualification.
The workflow retains `release-notes-handoff` for 30 days and stops without a
waiting runner, prepared commit, manuals build, release PR or qualification
record/check. Its immutable origin remains on the release branch. Evidence,
artifact-upload, filesystem and actual assembly errors still fail normally.
Completed maintainer-written version sections skip all generation.

New patch notes link every evidenced issue/PR directly addressed by a described
change in the current release. Complete commit metadata includes each commit's
changed paths so the generator can match references to the source diff and
path-preserving digests. Previous notes supply style only. Historical examples
and routine origin/predecessor/version/manifest updates do not establish current
issue work. Release initialization and the PR wrapper add no fixed references;
actual new fixes to older issues remain eligible.

Copilot may return exactly `NOTES_REVIEW_REQUIRED` when an evidence extraction
or final synthesis cannot be completed reliably, including a material unresolved
issue-to-change relationship. Digest/prose validation rejects that signal and
the existing pending path exports the complete external JSON handoff. Ordinary
admitted work still uses Copilot automatically; byte/file/context limits and
setup/provider/quota/timeout/invalid-output fallback remain unchanged. Editorial
relevance is a model/reviewer decision, not a property proven by format checks.

To continue:

1. Download that Actions artifact and give `prompt.txt` to your chosen AI. It
   includes the full template, complete commit metadata, changed paths and the
   entire source diff. `changes.diff`, `canonical-prompt.txt`, the actual template,
   `manifest.json` and `README.md` are also included. Generated release files,
   PDFs and installer outputs remain excluded from the source diff.
2. Review and copy the AI's single-line JSON response, including its identity
   fields. JSON escapes preserve Markdown newlines through GitHub's input box.
3. In **Release qualification -> Run workflow**, select the same
   `release/v_X.Y[.Z]` branch and paste that JSON into `external_notes`.
   Leave `notes_mode` as `auto`, then run. A maintainer dispatch reuses the
   existing origin; it cannot initialize a release from another branch.

The continuation rebuilds the canonical prompt and checks repository, version,
release branch, source SHA and prompt SHA-256 before applying the same strict
editorial validation. A changed source/template needs a fresh bundle. Notes are
never executed, and invalid supplied JSON fails without changing the release.
The existing PR approval, version completion and manual publication follow once
assembly finishes; continuation adds no BASIC/FULL.

GitHub may not show **Run workflow** until the dispatch trigger reaches main.
The already registered workflow can also be dispatched at the release ref:
save the AI response as `response.json`, then run
`gh workflow run release-qualification.yml --ref release/v_X.Y.Z --field external_notes=@response.json`.
The bundle repeats this command with its exact branch. No preliminary protected
branch edit or new release is needed. Provider credentials and stderr remain
outside both handoff and rejected-response artifacts. Rejected provider prose
alone remains available for seven days.

Registered Markdown version markers accept word wrapping within a paragraph,
including LF and CRLF line endings. Preparation changes only the registered
version value and retains the surrounding layout and historical version prose;
consistency checks recognize the same wrapped markers.

Windows installer change detection excludes `winapp/README.md`,
`winapp/AGENTS.md`, and tests. Changes to these documentation and fixture paths
alone reuse the verified existing installer; runtime and build inputs still
require a fresh installer.

The draft attachment reader applies these same exclusions before reusing the
previous published installer. Post-tag attachment recovery keeps the original
candidate, merge, tag, and verified asset bytes unchanged.

## Review, tag and publish

Approve the bot PR once. Automation performs the protected standard merge,
verifies its actual parents/tree, creates the immutable version tag,
fast-forwards
unchanged develop to that same main commit, and fills an unpublished GitHub
Release
draft. No separate synchronization commit is created. Main history is linked
before
PR review; early approval is observed after assembly. Concurrent develop work
is preserved and reported separately.

Wait for **DRAFT_READY**, then review notes and all original attachments and
manually choose **Publish release**. A green assembly is not publication
approval.
There is no normal manual ZIP extraction, artifact copying or upload step.

After the complete draft and completion artifact are retained, a final job
automatically deletes only the exact `release/v_X.Y[.Z]` ref at reviewed Q.
It rechecks original asset sizes/digests, merge/tag identity, main/develop
ancestry,
open PR dependencies and other active release work, then uses a server-side SHA
lease. Missing synchronization keeps the branch. A moved ref or failed proof
blocks cleanup while preserving the ready draft. Retry only the failed cleanup
job; it uses the retained artifact and immutable Q even if the branch was
already
deleted after a lost response. Publication stays manual and needs no release
ref.
Completion executes Q's workflow, so this behavior starts with the first release
containing it in qualified develop, without a preliminary main installation.

On **TAGGED / DRAFT_PENDING**, resume completion for the same Q: retain M/tag,
reconcile any lost response against the complete server inventory, and add only
missing verified assets. Conflicting or already published assets are not
replaced.
Parallel develop work blocks unsafe synchronization, never a correct tag/draft.

## Artifacts and exceptional diagnosis

Do not create deployment directories, ZIPs or installers during ordinary source
work unless explicitly requested. Hosted release assembly owns the production
ZIP,
all four manual PDFs, manifest/inventory/version metadata, checksums, release
notes
and permanent assembly/completion receipts. Ignored cache holds diagnostics.

Local audits/builds are exceptional diagnosis or explicitly requested recovery,
not a routine release staircase. Do not repeat green hosted source
qualification.
For an observed assembly failure inspect its compact summary and failed owner's
referenced diagnostics, repair the cause and resume only the necessary stage.

Preserve stable tags, published assets, old origins and original evidence.
Never directly update protected main/develop or weaken server protections.
Human browser/hosting smoke checks remain distinct from assembly. Historical
release refs retain the separately approved retirement workflow; automatic
cleanup
only targets the current ready schema-4 draft. See the
[lifecycle](docs/RELEASE_LIFECYCLE.md) and
[maintainer checklist](docs/MAINTAINER_RELEASE_CHECKLIST.md).

The reviewed develop history policy must admit main's release merges while
retaining non-force updates, deletion protection, exact Actions checks and no
bypass. The example ruleset describes that target configuration; code never
edits
live protections. A remaining `required_linear_history` rule blocks the release
PR with an explicit diagnostic. Ordinary feature integration still requires one
qualified commit directly above develop.

Existing published content-copy synchronization commits remain historical data.
The next release can retain them through its selected source and then join both
branches by a normal FF. Immediate realignment of already diverged refs needs a
separately reviewed migration; release completion never resets or rebases them.
