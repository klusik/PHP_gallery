# Automatic reviewed release lifecycle

The maintainer starts a release by creating `release/v_X.Y` or `release/v_X.Y.Z`
from current `develop` and pushing that branch. After preparation succeeds,
approve the bot-created PR to `main` once. Automation performs the protected
standard merge, creates the immutable tag and attempts safe develop synchronization.
Create or publish the GitHub Release manually using the retained artifacts.
No normal step asks for a SHA, run ID, operation mode or deployment approval.

## Git and authorization contract

- D0 is the exact develop commit pushed as the new release branch.
- O introduces the origin and predecessor pin in one single-parent commit after D0.
- Q is the final prepared and fully qualified release head.
- P is the previous immutable tagged main release merge.
- M is the new main merge, with `parents(M)=[P,Q]` and `tree(M)=tree(Q)`.
- Develop can advance to Q through a true non-force fast-forward. It never advances
  to M, and automation creates no merge or replay commit on develop.

Feature agents write only their explicitly authorized working branches. This
future maintainer-triggered release workflow is the sole automatic protected
operation in this contract; implementing it grants no permission to execute a
production release or change Rulesets, Environments, historical tags or releases.
Feature integration still requires one logical squash, exact-SHA hosted CI and
a maintainer-performed non-force FF to develop. Published history stays intact.

The active main policy requires one human approval, stale-review dismissal,
merge commits only, no force/deletion and the Actions `Release qualification`
context. The active develop policy requires linear history, non-force/deletion
and the Actions `Complete required CI matrix` context, without a mandatory PR.
The scripts also require the complete installed/effective policy and empty bypass
inventory. Missing or redacted policy data is BLOCKED, never implicit approval.
No new privileged integration or policy bypass is introduced.

## Ownership and event handoff

`release-qualification.yml` reacts to a normal push. `start-new-release.mjs`
initializes the existing release ref after verifying maintainer permission,
selected develop ancestry, current tagged main, previous Q/tree, version ordering
and target-tag absence. Existing immutable origins, including v_0.126, are reused
without rewriting. An interrupted first run may reuse its original O or prepared Q;
a different event or divergent writer fails the branch lease.

Initialization, preparation and qualification continue in the same run. Bot
pushes do not need another push event. Source Stage A/B remains before metadata,
AI notes, locked TinyTeX/manuals and manifest generation. The existing complete
PHP/Node/WinApp/Chromium/MySQL/MariaDB and positive-package matrix qualifies exact Q.
The gate emits checks whose external IDs bind kind/run/attempt/Q, plus the complete
provenance record. Manual-publication packages, four PDFs, notes, SHA256SUMS and
integrity metadata are independently rehashed before the bot creates or reuses one exact-Q PR.
The independent WinApp version is preserved; its installer is built only for
shipped WinApp changes. WinApp regression coverage remains mandatory in CI.

`release-promotion.yml` reacts to human `pull_request_review: submitted` approval.
It uses the qualified PR head carried by that event, not a default-branch dispatch.
This permits the first release carrying the new completion code without an
intermediate main commit. Before candidate code executes, inline checks require a
successful Actions release binding on Q, wait for the run if approval arrives
immediately, and bind its server workflow/event/attempt to the retained exact-Q
record. The completion helper then requires the complete successful latest attempt, exact record/origin/predecessor,
full PR detail, final effective owner approval on Q and active compatible policies.
It polls the asynchronously computed test merge for at most sixty seconds, while
refusing changed P/Q or an explicit conflict. It checks the preview parent order/tree, rechecks P and Q, and asks the
server to merge with `merge_method=merge` and expected head Q. GitHub enforces the
review and status rules. Completion independently verifies the resulting M before
creating a tag. A concurrent main change or differing merge result stops tagging.
GitHub's merge API has no conditional expected-base field: the guards narrow this
race and post-merge verification refuses a mismatched result; they cannot roll
back an already server-accepted merge. Live isolated acceptance must measure it.

Tagging occurs in that same job because token-created closed/push events cannot
be assumed to start another workflow. A real human merged-PR `closed` event is an
additional retry path. Re-running a failed completion job re-reads the full PR:
if merge already succeeded it verifies M, then creates the missing tag. An
existing correct tag is read-only; a conflicting tag fails without moving it.
No GitHub Release create/edit/publish operation exists in this pipeline.

GitHub can require separate workflow approval for PRs opened with GITHUB_TOKEN.
Release PRs to main therefore do not trigger the redundant generic PR matrix;
full exact-Q release qualification has already run. The required release context
is authored by the same Actions App and explicitly verified. Review-triggered
completion is human initiated. Actual GitHub routing and check eligibility remain
a live acceptance item until an isolated E2E run proves them.

## Previous release and immutable evidence

Tag-ref object, peeled main commit P, previous release-side Qprev, Git tree and
SHA-256 artifact digests are distinct identities. P need not be an ancestor of D0
or Q. Previous Q must be retained in selected develop ancestry, or the existing
historical linear reconciliation proof must establish complete content continuity.
Audits and AI/WinApp comparison scope use Qprev and endpoint tree comparisons.

Published predecessor assets retain their original SHA-256 validation. A newer
tagged predecessor may have no GitHub Release yet: resolve its two-parent merge,
exact successful hosted Q qualification and complete bot PR/human review instead.
Unavailable/expired evidence fails closed. Upload the retained qualification archive
with the manual publication for durable evidence. The completion run retains
`release-evidence.json` and its SHA-256 beside `release-completion.json`; upload
that permanent proof as well as the preparation assets when publishing manually. The explicitly pinned v_0.125
UNKNOWN_LEGACY bootstrap remains historical support; no other missing evidence
inherits that exception. Never change the immutable v_0.126 record or publication.

## Develop synchronization

After tag creation, automation independently verifies develop policy and exact Q
CI. If current develop equals Q, it reports RECONCILED_FF. If current develop is
an ancestor of Q, it proves selected ancestry and no new merge commits, rechecks
the remote head, and uses a normal Git push to Q. The server enforces protection
and rejects non-fast-forward races. It verifies the resulting exact remote SHA
and retains Q's hosted qualification rather than fabricating a new green check.

If develop advanced in parallel, completion keeps the correct tag and reports
BLOCKED_DEVELOP_ADVANCED with the actual develop SHA. It never rebases, merges,
rewrites or rolls back a completed release. Other policy/permission/race failures
are BLOCKED_DEVELOP_POLICY_OR_RACE. Reconciliation status is distinct from tagging.
A separately reviewed working-branch solution may then be needed; no automatic
conflicting replay is part of the normal lifecycle.

## Retired and historical mechanisms

The manual Start New Release workflow and separately approved reconciliation
workflow are removed. Promotion workflow dispatch modes, deployment approvals,
first-release bootstrap dispatch, red-check override and automatic GitHub publisher
are removed from the active path. The optional separately approved release-ref
retirement workflow remains because it has independent historical evidence consumers;
it is never a prerequisite for creating, reviewing, merging or tagging a release.
Historical origin/predecessor/reconciliation parsers remain for already retained
records and tags. The old reconciliation proposal, evidence-upload and CLI writers
are removed after verifying that only their superseded workflow/tests consumed them.
The retained inspection API accepts read-only plan mode; it authorizes no new recovery release.

The source hotfix is `9d187d3663f96f7fa10451001d61ddb31e276479`:
https://github.com/klusik/PHP_gallery/commit/9d187d3663f96f7fa10451001d61ddb31e276479
Transferred permanent repairs include exact check identity selection independent
of duplicate names, complete PR-list/detail binding (including merged_by), bounded
complete release pagination, permission diagnostics and immutable parent/tree/drift
checks. Existing SHA-256 asset readers and deterministic packaging stay supported.
Actual run 38019768641 also confirms that the Actions jobs endpoint includes
programmatic qualification checks beside the runner aggregate. The coverage reader
resolves their check details and excludes only successful exact release/run/attempt/Q
bindings; mandatory workflow owners and independent qualification-check validation
remain required. Historical candidate/release proof uses the same coverage projection
and binds the aggregate check to the selected run/attempt/SHA. Foreign or unbound job
rows, malformed identities and incomplete inventories are not hidden.
The v_0.126 recovery identity, attestation comments, one-off environment and special
recovery dispatch are not adopted. Its branch cleanup remains PENDING until final
CI, complete transfer review and durable feature preservation/integration are proven.

## Verification limits and isolated acceptance

Central registered tests exercise real isolated Git objects and bare remotes,
normal release pushes, origins, both version forms, retry, exact Q, single approval,
merge parents/trees, tags, interrupted merge-to-tag handoff and parallel develop.
The completion fixture runs two successive release versions before manual publication;
the existing linear graph independently proves M is not required in next-Q ancestry.
Fixtures do not prove GitHub's actual permission, routing or protection behavior.
The candidate workflow retains actual GITHUB_TOKEN policy readback separately as
`live-release-readiness`; a successful source matrix is not live release acceptance.

Before claiming operational completion, run the same workflows in an owner-approved
disposable repository with mirrored policies and existing token permissions. Use
two successive version branches, one exact-Q human approval each, and negative
unapproved/red/stale/main-drift/tag-conflict cases. Verify review routing before the
workflow reaches default main, rejected protected updates, no extra CI approval,
M parents/tree, restart after merge and develop FF/parallel refusal. Never use a
production release as this experiment. Provisioning that separate repository or
changing its policies requires separate authorization; no such resource is created
by the implementation task. The first planned production version remains v_0.126.1.

## Measured workflow-token limitation (2026-10-10)

[Candidate run 38018137474](https://github.com/klusik/PHP_gallery/actions/runs/38018137474)
retains the actual `live-release-readiness` readback: both main and develop are
BLOCKED because GitHub omits `bypass_actors` from the existing GITHUB_TOKEN response.
Owner-authenticated readback confirms the installed lists are empty, but that
is not a reusable token proof for each later release operation. The
[GitHub rules API](https://docs.github.com/en/rest/repos/rules#get-a-repository-ruleset)
explains that this field is returned only to a principal with write access to the
ruleset. Merely adding read-only Metadata/Administration access cannot be assumed
to solve the redaction.

The minimal next decision is a separately approved policy-evidence reader that
can see the complete installed policy, executes only allowlisted GET operations,
and never supplies its credential to the merge/tag/FF worker. Prove visibility
and isolation before configuring it; the existing write worker continues to use
GITHUB_TOKEN and server protections. No such integration, credential or server
setting was created by this feature. Until it is approved and qualified, live
automatic completion remains BLOCKED despite successful source CI.
