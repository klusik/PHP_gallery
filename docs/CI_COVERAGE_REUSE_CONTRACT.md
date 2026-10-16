# CI qualification and release ownership

## Current operational contract

Feature pushes and the final source squash receive hosted BASIC syntax and named
declaration checks. `scripts/audit.php` and its registries remain the sole test
orchestrator. BASIC is feedback; it does not replace complete integration.

In **Integrate into develop → Run workflow**, select **main or the intended
feature branch** in GitHub's workflow-ref selector, then select the source branch.
An explicit repository-owner dispatch authorizes that immutable workflow/test
revision. Ordinary test, registry and evaluator changes need no preliminary main
installation. The selected workflow actually executes its changed tests.

The final source must be one commit S above observed develop P. Unprivileged
hosted preparation generates runtime modules, inventory and manifest in dependency
order. The privileged publisher accepts only those generated files, leases the
feature head, folds them into one final A when necessary and obtains fresh BASIC.
A separate authority checkout scans clean A with BASIC; candidate execution has
read-only permissions and no persisted write credentials.

FULL runs the complete required matrix on A: shared source contracts, portable
Node/WinApp, packaging on three operating systems, four shards for each of three
databases, four shards for each PHP 8.1/8.5 source environment, required Chromium
and the aggregate. All 28 actual native owners must pass. Failed, missing,
duplicated, cancelled or skipped mandatory owners are not successful evidence.

The owner-selected feature dispatcher may publish the protected non-force FF
after that complete qualification. A feature push or an unselected pull request
does not authorize the write. GitHub branch protections remain enforced.

## Seal execution before publication

The read-only **Retain integration execution** job uploads the original
`develop-integration-execution/develop-execution.json` before any develop write.
`qualify_only` performs preparation and complete qualification without writing
develop. To finish later, supply its run ID as `qualified_run`.

Reuse requires the identical candidate SHA, complete Git input tree and develop
base. The original native owner IDs, run/attempt, workflow revision, explicit
owner dispatch, independent BASIC report and complete provider-authenticated
archive are rechecked. A failed publication after successfully sealed FULL may
resume without another FULL. Changed source/test/generated inputs invalidate
reuse. Cancelled or superseded execution is rejected.

Integration records use schema 2 with `feature_qualification`: input tree,
independent BASIC plus original artifact identity, and original FULL execution
run/attempt/workflow SHA. Publication has its own run identity and exact-candidate
Actions checks. Whole-tree input identity is the conservative shared closure;
release-owned generation does not become a new functional candidate.

A required-check propagation rejection receives at most six writes, separated
by two seconds, with fresh leases and evidence. Other or ambiguous API failures
remain failures; use sealed execution to recover rather than rerunning tests.
The unchanged required context is `Complete required CI matrix`, Actions app
15368. The final integration record is retained for provenance and diagnostics.

The publisher exposes that verified FULL through both Checks and Commit Statuses.
GitHub can omit dispatch-suite Checks from its branch-rule rollup despite their
successful REST response. Only the privileged publisher has `statuses: write`;
it authenticates the Actions creator in the creation response and binds the same
required context to the exact SHA/run. Before every FF it rechecks that immutable
status ID, successful state and workflow URL, retaining `matrix_status_id`.
Combined Status omits creator; its unchanged ID binds the authenticated author.
Both representations use the same real FULL.

## CI-only installation on main

When the maintainer needs changes installed on main before the next release,
base a CI-only feature on observed main and dispatch **Basic source checks** with
`install_ci`. Squash the complete prepared tree, including bot commits, to one
commit above observed main first. The workflow rejects multiple commits before
generation and stops before FULL if generation publishes a child; apply the
squash checkpoint and obtain fresh BASIC. FULL retains that final SHA.
This reusable path prepares the branch and runs FULL plus the
authoritative release audit. It requires all 29 native owners, unchanged
source/main leases, CI/test/docs-only scope and effective main protections.

Only after the original installation record is uploaded does the workflow issue
the required `Release qualification` check and open a bot-authored PR. One real
human approval and normal protected auto-merge remain mandatory. No temporary
transport workflow, hard-coded current SHA/version exception, tag or release is
created by installation. Product changes stay on the normal develop path.

## Release trusts protected develop

**A commit admitted to protected develop is qualified.** A separately authorized
release freezes source A from that branch. It does not rerun BASIC/FULL, download
historical test archives, compare evaluator versions or requalify the preceding
release. Historical Actions artifact expiry cannot invalidate a new release.

The release workflow assembles B: version/date metadata, editorial patch notes,
all four maintained manual sources/PDFs, production inventory, final manifest,
conditional installer and complete attachments. It still detects build failure,
missing files and stale generated hashes. Those are assembly failures, not a new
source qualification stage.

Lifecycle schema 4 explicitly records `protected-develop-v1`, frozen source/tree
and assembly outcome. It carries no synthetic BASIC delta or invented FULL PASS.
Git operations bind the actual main/release heads, origin, existing stable tag,
reviewed Q, merge parents/tree and immutable tag. Untagged protected main
maintenance merges are followed through their first-parent history to the real
stable tag, without changing the tag or replaying maintenance tests.

The workflow opens the release PR. After one human approval it completes the
protected merge, version tag, safe develop synchronization and authenticated
attachment filling of an unpublished draft. Manual **Publish release** stays
separate. Parallel develop work blocks an unsafe synchronization, not a correct
already completed tag/draft.

Release preparation links the frozen main history before review without changing
the prepared tree. Schema-4 completion publishes a single-parent equivalent tree
above unchanged selected develop, using actual assembly qualification and scoped
Actions status authority. The permanent receipt binds the result/tree/base;
future initialization trusts protected develop instead of reopening historical
reconciliation archives. Only the post-assembly observer handles approval.

## Compatibility and acceptance

Readers retain historical integration schema 1, authority-update records and
release schemas 1–3 for immutable old evidence. Their old proof requirements
apply to those records; they are not prerequisites for new schema-4 releases.
Historical finite pins remain historical compatibility, never new permissions.

The registered feature qualification fixture exercises owner-selected feature
execution, complete adverse owner outcomes, corrupted archives, changed inputs
and publication recovery. Existing real-Git release/approval/tag/asset fixtures
retain their meaningful negative assertions. Update workflow consumers with the
changed ownership; do not weaken product assertions or add baseline debt.

Operational acceptance requires hosted feature qualification and the reviewed
installation. Report the exact candidate/base/workflow/run, required owner
results, repair iterations, avoidable FULL starts and unperformed live lifecycle
coverage. Human approval, publication and production smoke tests remain distinct.
