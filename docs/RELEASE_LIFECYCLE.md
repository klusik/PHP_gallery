# Automatic reviewed release lifecycle

The maintainer starts a release by creating `release/v_X.Y` or `release/v_X.Y.Z`
from current `develop` and pushing that branch. After preparation succeeds,
approve the bot-created PR to `main` once. Automation performs the protected
standard merge, creates the immutable tag, attempts safe develop synchronization
and stages one unpublished GitHub Release draft with the existing qualified notes.
Open the draft, add optional artifacts and manually choose Publish release.
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
The scripts verify the installed ruleset ID/name/source, active branch target and
all effective branch rules. Exposed bypass inventories must be empty; exposed
current-user bypass must be `never`. An omitted `bypass_actors` is reported as
`NOT_RETURNED_SERVER_ENFORCED`, never synthesized as `[]`. Server enforcement
remains authoritative for hidden bypass membership. Malformed/null inventories,
visible bypass, unknown rules, extra required contexts and changed review or
history requirements block completion. This client visibility correction applies
to both main and develop; it changes no server protection or credential.

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

Qualification opens/reuses the full-detail bot PR and automatically dispatches
`release-promotion.yml` at the exact release branch Q. The filename already exists
on default main with `workflow_dispatch`, which supplies GitHub registration;
the dispatch `ref` selects the new workflow from Q. No new commit on main, manual
form, workflow-run approval or extra integration is required. Inputs are populated
by qualification and bind PR number, Q, branch and run. The observer waits for
that qualification run to finish successfully before executing Q's helpers.

This is the first-release bootstrap, independent of new review subscriptions
being installed on main. GitHub documents
[dispatch branch selection](https://docs.github.com/en/rest/actions/workflows#create-a-workflow-dispatch-event)
and the [GITHUB_TOKEN dispatch exception](https://docs.github.com/en/actions/how-tos/write-workflows/choose-when-workflows-run/trigger-a-workflow).
The existing human review/merged-PR events remain additional completion paths;
the lifecycle does not require those events to route the new workflow before
its first merge. Token-created closed/push events are not completion triggers.

Inline bootstrap verifies the complete same-repository bot PR and latest Actions
check binding, then the successful run/path/event/attempt and retained record,
before checking out exact Q. The helper validates full qualification, immutable
origin/predecessor, actual required checks, effective policies and final owner
approval of Q. It observes reviews in one-minute intervals for up to four hours.
A still-pending review automatically dispatches another exact-Q observer below
the five-hour job limit. Observable active workers are reused; per-PR concurrency
serializes possible overlapping dispatches. Drift or a closed unmerged PR stops
without writes. This waiting consumes hosted runner time while review is pending.

A safe existing `auto_merge` object is accepted only for standard `merge` enabled
by the workflow bot or human owner, with the same exact bot PR identity. Native
Auto-merge was inspected through GitHub's actual GraphQL input schema: it supports
`expectedHeadOid` but no conditional expected-base P. The lifecycle therefore
does not arm a long-lived native Auto-merge request while awaiting approval.
Instead, its automatic observer rechecks P/Q immediately after approval, validates
the test merge parents/tree and requests protected server merge with expected Q.
If native Auto-merge or the owner already merged, it inspects that actual M and
continues. Server review and required checks remain mandatory.

GitHub's asynchronous test merge is polled for at most sixty seconds. Main and Q
are rechecked before the server merge; the accepted M is independently required
to have `parents(M)=[P,Q]` and `tree(M)=tree(Q)` before any tag write. The merge API
has no expected-base field, so a concurrent accepted base update remains a narrow
server race: verification refuses tagging a differing result and cannot undo an
already accepted merge. This limit is unchanged and requires live acceptance.

PR creation/dispatch and completion each retry temporary interruptions up to
three times, re-reading and validating mutable identities. Each approval observation
also retries temporary read failures rather than silently abandoning the first-release
worker; persistent unreadable or invalid state still blocks without writes. Completion observes
an existing M, creates only a missing tag and retries transient develop transport
failure. A correct tag is read-only; a conflicting tag fails without moving it.
A pending review continues automatically, while a persistent safety blocker is
reported rather than bypassed. After verified M/tag, the same completion job
reads unchanged PATCH_NOTES.md from the exact Q checkout and selects the same
version section previously saved as release-notes.md. It uses the existing complete,
paginated GitHub Releases inventory, including drafts, before any create-only POST.
DRAFT_CREATED means a single unpublished, empty-asset draft was verified by ID;
DRAFT_ALREADY_EXISTS preserves existing manual edits/assets and ALREADY_PUBLISHED
does not modify the public release. Version/tag conflicts fail closed. Draft API
failure reports TAGGED / DRAFT_PENDING without undoing M/tag or requiring another
merge; a retry inventories all drafts before deciding whether to create.
Only the human maintainer uploads optional packages and clicks Publish release. The generic PR matrix excludes main to avoid redundant token-created
PR workflow approvals; exact-Q release qualification owns the required context.

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
workflow are removed. Legacy manual promotion/publication modes, deployment approvals, owner-entered
first-release bootstrap inputs, red-check override and automatic GitHub publisher
are removed from the active path. Machine-populated completion dispatch is the
normal bootstrap and approval-wait continuation, never publication authority. The optional separately approved release-ref
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
unapproved/red/stale/main-drift/tag-conflict cases. Verify automatic ref dispatch before the
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

The P0 repair authorized on 2026-10-10 removes that unavailable client-side
precondition for both branches. It does not claim hidden lists were read. Installed
identity, branch conditions and effective protections are independently checked;
actual token diagnostics retain the omission and server-enforcement status. No
attestation comment, privileged reader, new secret or server setting is needed.

The feature-only `probe`/`probe-child` dispatch modes permit live observation of
selected workflow ref and token-created child dispatch using the existing token,
with contents read and no merge/tag/develop operations. They retain routing and
policy artifacts. They cannot run on release/main/develop and cannot qualify a
release. A green transport probe proves routing and token visibility only.

Measured on 2026-10-10 at source `890ad88b8fee6f16f1c9de56818e4987e9979f91`:
[parent probe 38038292598](https://github.com/klusik/PHP_gallery/actions/runs/38038292598)
and [token-dispatched child 38038309384](https://github.com/klusik/PHP_gallery/actions/runs/38038309384)
both succeeded. Their retained `dispatch-routing.json` identifies the new workflow
at `refs/heads/feature/ci_fix`; the child actor is `github-actions[bot]`, even though
main still contains the legacy dispatch-only workflow. Both release completion
jobs were deliberately skipped and the probe jobs had no contents write permission.
Actual token readback verified rulesets 24808772/24808873 and effective controls,
reported missing bypass membership honestly and observed current-actor bypass
`never`. This proves the chosen automatic dispatch transport under current repository
settings without assuming new review events exist on main. It does not prove a
real PR approval, required-check eligibility, merge, tag or develop update.

The owner declined a disposable test repository for this task. Full live
approval/merge/tag/FF and the next release are therefore NOT RUN, pending the
maintainer's subsequent acceptance. Feature CI and real-Git fixtures must not be
presented as proof that a production release has completed.
