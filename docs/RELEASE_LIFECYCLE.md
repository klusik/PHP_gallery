# Automatic reviewed release lifecycle

The [manual maintainer checklist](MAINTAINER_RELEASE_CHECKLIST.md) gives the exact
feature/integration/release/publication/Pages/retirement sequence. Completion
automatically attaches original qualification assets and both permanent receipts
before reporting **DRAFT_READY**. Historical published releases remain immutable.
Automatic completion receipts are revalidated against the retained qualifier,
immutable tag/Q/tree, live bot PR and exact effective owner approval. If protected
linear develop retains Q, retirement inspection recognizes the normal automatic
FF without a historical `release-reconciliation.yml` run. Legacy equivalent-replay
receipts retain their existing verification path.

The maintainer starts a release by creating `release/v_X.Y` or `release/v_X.Y.Z`
from current `develop` and pushing that branch. After preparation succeeds,
approve the bot-created PR to `main` once. Automation performs the protected
standard merge, creates the immutable tag, attempts safe develop synchronization
and stages one unpublished GitHub Release draft with the existing qualified notes.
Review the complete **DRAFT_READY** draft and manually choose Publish release.
No normal step asks for a SHA, run ID, operation mode or deployment approval.

## Git and authorization contract

- D0 is the exact develop commit pushed as the new release branch.
- O introduces the origin and predecessor pin in one single-parent commit after D0.
- Q is the final prepared release head B, with BASIC changed-source and
  release-owned-input evidence bound to FULL-qualified develop A.
- P is the previous immutable tagged main release merge.
- M is the new main merge, with `parents(M)=[P,Q]` and `tree(M)=tree(Q)`.
- Develop can advance to Q through a true non-force fast-forward. It never advances
  to M, and automation creates no merge or replay commit on develop.

Feature agents write only their explicitly authorized working branches. This
future maintainer-triggered release workflow is the sole automatic protected
operation in this contract; implementing it grants no permission to execute a
production release or change Rulesets, Environments, historical tags or releases.
The #185 target uses BASIC on feature pushes and the final squash. The feature
must be one authored commit S on top of develop P before the maintainer selects
it with the Actions **Integrate into develop** button. That action refuses
unsquashed history, runs the complete FULL matrix exactly once on final candidate
A, and fast-forwards develop only after exact-A FULL passes. Agents never perform
that protected write. This target is not active until installed on main and
verified by real hosted CI; until then the currently installed required workflow
remains authoritative. Published history stays intact.

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

### Bootstrap the known broken integration installation

The integration run [38218271690](https://github.com/klusik/PHP_gallery/actions/runs/38218271690)
failed before FULL because upload-action hexadecimal output did not match the
algorithm-qualified digest required by the main-owned publisher. A feature fix
cannot update that publisher's workflow. The owner can install the repair through
a **new, genuinely FULL-qualified release**, using the retained schema-2 release
bootstrap and the ordinary protected PR approval, merge, tag and draft lifecycle.
No server rule, red override, fabricated qualification check or protected ref
write is needed from the preparation tool.

From a clean checkout containing the reviewed repair, inspect the unpublished
`0.127.3` plan, only while its branch, tag and draft are absent:

```powershell
node .github/scripts/integration-bootstrap.mjs --version 0.127.3 --source feature/190-build-a-dedicated-php-gallery-website-on-github-pages
```

The source must be pushed and reduced to one authored commit over the actual
remote develop. The tool checks the owner's identity, installed/effective main
and develop protections, unchanged predecessor, fresh target and source scope.
It accepts only `klusik/PHP_gallery`, defective main
`7dcfadf153b1c941fd81edee43272f8accb475a1` and the independently observed integration
workflow blob `749a48265f07a10de636ff07bfeacc6c1d9d1cb4`. Missing integration proof
alone never enables recovery. Only repair workflow/helpers, their tests and
permanent documentation may differ from develop; product and runtime changes,
existing origin markers, rulesets and environment changes are rejected.

After reviewing the plan, repeat the command with `--prepare`. It creates local
unattached Git objects and prints `candidate_sha` and `push_command`. It does not
switch/create/update a branch, alter the index/worktree, push, tag or publish.
The origin O has the actual develop A as its sole parent. The repair C has O as
its sole parent and preserves the exact source tree, adding only immutable origin,
independently checked predecessor and recovery marker files. For `0.127.3` it
**reuses the original O** `d4f9abd78bebb11a691793b6b1f9815188b21b0e` and both original
metadata blobs byte for byte. Deleting the failed release branch did not retire
that identity. The tool verifies its introduction, ancestry, current main and
predecessor pins before reuse; any mismatch blocks rather than regenerating it.
Only genuinely unused versions create a new O. No origin is edited or replaced.

The maintainer executes the printed push command to create the new release branch.
Its **empty expected ref lease** requires the branch still to be absent: despite
Git's `--force-with-lease` option name it cannot overwrite any existing ref. If any
pin changes, rerun the plan and preparation rather than editing the record.
The push runs `Release qualification`; the selector verifies the complete O/C
chain and exact source-tree overlay, then runs the existing full release bootstrap.
It rebuilds notes and all four manuals, prepares generated state, executes the
whole central release matrix including required Chromium/database/WinApp/package
owners and the authoritative release audit, and retains schema-2 evidence with
explicit `integration_recovery` provenance. There is **zero inherited integration
coverage**. Existing native job labels are preserved for older schema-2 consumers.

Wait for the complete workflow and bot-created release PR. Review/approve that PR
once; normal server-enforced promotion performs the protected merge, immutable tag
and unpublished draft. Automation fills the draft; the owner reviews it and publishes it.
Develop synchronization remains conditional on its current ancestry and protection
checks; report a blocked reconciliation separately. No integration action is needed
before this exceptional release. Once the repair reaches main, later feature work
returns to BASIC -> Integrate into develop -> FULL -> normal release-delta coverage.
After successful installation this exact-main recovery is unavailable; its parser
is retained only as immutable history and can be retired after all recovery evidence
consumers are migrated. Production publication and live recovery are not authorized
by implementing or testing this tooling.

Prepared integration archives and release-delta archives use algorithm-qualified
`sha256:<64 lowercase hexadecimal characters>` identities. The upload action's
`artifact-digest` output is bare hexadecimal; each producer adds `sha256:` before
passing it to the authenticated reader. The reader still requires exact equality
with independent GitHub artifact metadata and verifies the complete ZIP bytes.
Run `38218271690` exposed the missing producer prefix before FULL started; the
workflow-to-publisher regression fixture covers both producers. Since integration
runs code from `main`, a feature-only correction does not repair the installed
dispatcher. It must first reach `main` through an authorized maintainer installation;
rerunning the old main workflow cannot consume the feature's corrected YAML.

### Recover an already-qualified develop head

After the recovery implementation is reviewed and installed on `main`, the
repository owner can use **Integrate into develop → Run workflow → main**, select
the original feature branch and enable **recover_existing**. This is an exceptional
qualification recovery, not the normal integration route. The feature head must
equal the current protected develop head exactly and retain one parent. Its newest
candidate workflow must be a successful maintenance FULL dispatch with all native
matrix owners and exact-SHA qualification checks. If the newest evidence is missing,
red or BASIC-only, obtain a fresh maintenance FULL on that same feature SHA first.

Recovery selects the existing candidate and its actual historical parent, requires
both the original actor and rerun actor to be the repository owner with maintain/admin
permission, and repeats the main-owned FULL matrix on the exact candidate. Preparation
is read-only `prepare_candidate.php --check`; no generated child is created. The
publisher rechecks source/develop leases and writes only Actions check metadata and
the retained artifact. It never writes a Git ref, merge, tag or release. Missing,
duplicate, red, skipped or racing evidence blocks recovery.

The artifact retains schema 1, the original eleven native owner names and all true
source/parent/run identities. Additional `recovery_mode`, `observed_develop_sha` and
`ref_write:false` fields explicitly distinguish recovery from a fast-forward.
Existing schema-3 release consumers can authenticate it without rewriting their
immutable origins or changing the selected SHA, provided the original main and
predecessor pins still match. After the recovery run completes successfully, rerun
the failed release qualification workflow. Human review,
protected main promotion and manual GitHub Release publication remain unchanged.

A feature-branch implementation or green fixture run does not install this authority.
Dispatch recovery only after the reviewed workflow/helper has reached `main` through
an authorized maintainer installation. Agents do not perform that protected write.

**Bootstrap limit:** installing recovery advances main. A release origin frozen
before that installation cannot be rebased onto the newer main: its original main
and predecessor identities are immutable. This applies to the interrupted
`v_0.127.3` origin if main advances before its recovery. The known-defect FULL
bootstrap above avoids that advance: it installs the repair as the still-unpublished
`0.127.3` release itself and retains the original origin, while all pins still
match. If they stop matching, keep the old origin as historical evidence and use
a genuinely new release origin. Do not reset protected refs, edit old pins, forge artifacts or
claim that recovery retroactively authorizes an advanced-main release. Recovery is
usable for later interrupted releases whose origins already selected main with
this implementation installed.

`release-qualification.yml` reacts to a normal push. `start-new-release.mjs`
initializes the existing release ref after verifying maintainer permission,
selected develop ancestry, current tagged main, previous Q/tree, version ordering
and target-tag absence. Existing immutable origins, including v_0.126, are reused
without rewriting. An interrupted first run may reuse its original O or prepared Q;
a different event or divergent writer fails the branch lease.

Initialization and preparation continue in the same run. Bot pushes do not need
another push event. Under the #185 target, release preparation starts from
FULL-qualified A and produces B with release notes, four locked-toolchain
manuals/PDFs, inventory and manifest. It runs BASIC `--changed` against
immutable A..B plus release-owned-input proof. It does not rerun browser,
database/HTTP, functional, Node runtime, WinApp or packaging regression suites on
B. Package and conditional installer generation remain production outputs, not
regression evidence. The gate binds the check and retained record to B and its
exact release evidence. The independent WinApp version is preserved, and the
installer is built when required by the release/package contract.

The human-approved release PR, protected main merge M, tag and draft behavior
remain unchanged. Once release completion has produced Q, a pure develop
fast-forward to Q needs no second FULL run because the FULL-qualified A is its
source ancestor and release preparation adds only approved release-owned inputs.
Parallel develop work still blocks synchronization.

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
before checking out exact Q. Under the #185 target the helper validates the
record binding FULL coverage at A to the BASIC delta and release-owned inputs at
B/Q, immutable origin/predecessor, actual required checks, effective policies
and final owner approval of Q. It observes reviews in one-minute intervals for up to four hours.
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
DRAFT_CREATED is only an intermediate staging result, not a ready draft;
DRAFT_ALREADY_EXISTS preserves existing manual edits/assets and ALREADY_PUBLISHED
does not modify the public release. Version/tag conflicts fail closed. Draft API
failure reports TAGGED / DRAFT_PENDING without undoing M/tag or requiring another
merge; a retry inventories all drafts before deciding whether to create.
Only the human maintainer clicks Publish release. The generic PR matrix excludes main to avoid redundant token-created
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
Unavailable/expired evidence fails closed. Completion automatically attaches the
qualification archive, `release-evidence.json` and its SHA-256 to the draft for
durable publication evidence. Actions retains `release-completion.json` and the
original diagnostic artifacts as well. The explicitly pinned v_0.125
UNKNOWN_LEGACY bootstrap remains historical support; no other missing evidence
inherits that exception. Never change the immutable v_0.126 record or publication.

## Develop synchronization

After tag creation, automation independently verifies develop policy and the
release record binding FULL coverage on A to the BASIC release delta on B. If
current develop equals Q, it reports RECONCILED_FF. If current develop is
an ancestor of Q, it proves selected ancestry and no new merge commits, rechecks
the remote head, and uses a normal Git push to Q. The server enforces protection
and rejects non-fast-forward races. It verifies the resulting exact remote SHA
and retains the exact-Q release check and its authenticated A/B evidence rather
than fabricating a new green check.

This post-release FF introduces no source changes and requires no new integration
matrix: the release record already binds FULL A and approved release-only changes
through B/Q. The last full develop integration belongs
before release preparation, when new feature work enters develop. Do not confuse
that integration requirement with synchronization after the completed release.

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
The retained historical candidate reader selects the newest published bound
qualification and highest attempt before considering success. A newer red/pending
check or missing aggregate cannot fall back to older green evidence. Complete
check/job pagination is bounded to five pages of 100 rows; short/conflicting pages,
duplicate identities or mandatory owners, and changing run/inventory snapshots
refuse qualification. This protects historical inspection and predecessor lineage;
it does not authenticate cross-stage per-child coverage or discover newer workflow
runs before their qualification checks are published. The proposed independent
develop verifier remains separate and unimplemented.
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

## Automatically verified draft assets

The completion job loads `manual-release-assets` from the exact successful
qualification run and attempt bound to Q. It inventories provider artifacts,
requires one successful producing job in that attempt and checks creation time
within that job, complete archive size and provider SHA-256. The existing bounded
ZIP parser rejects traversal, links, duplicates, unlisted bytes and corrupt CRCs;
entries are read as data without filesystem extraction or execution. The original
`final-integrity.json` and `SHA256SUMS` bind every prepared file to Q/base. Version
notes and package metadata are checked against Q before upload.

Required original files are `production.zip`, `core-manifest.json`,
`production-files.json`, `release-metadata.json`, all four `PHP_Gallery_Manual*.pdf`
editions, `qualification-evidence.zip`, `SHA256SUMS`, `final-integrity.json` and
`release-notes.md`. Verified merge/tag completion adds `release-evidence.json`
and `release-evidence.sha256`. The original notes and file bytes are preserved.

A required installer comes from the same run/attempt's `manual-winapp-installer`.
When installer generation is expectedly skipped, the filler reads the original
installer and `winapp-update.json` from the immutable pinned published predecessor.
The existing candidate gate authenticates predecessor qualification and receipt;
the filler additionally verifies the predecessor tag, unchanged shipped WinApp
source, Q's independent `winapp/VERSION`, provider digests, sizes and metadata's
installer SHA-256. Missing proof or assets blocks filling rather than rebuilding
an unchanged installer or borrowing an unrelated latest release. This permanent
compatibility path preserves downloads and updates across CMS-only releases.

Uploads use create-only asset names and target the verified unpublished draft ID
and immutable tag. Complete paginated inventories detect duplicate names. Every
existing required asset is downloaded and compared with original bytes before
uploads begin. Identical files remain; conflicting files are never deleted or
replaced. The workflow serializes completion by PR; independent concurrent
create-only uploads and lost responses are reconciled by downloading the observed
asset. A partial, missing or conflicting upload reports TAGGED / DRAFT_PENDING
with its filename. Rerunning completion revalidates the same Q/run/attempt/M/tag
and fills missing files. No extra manually dispatched upload action is introduced.
The immutable merge/tag is never rolled back. Published releases, including
v_0.127.4 and all older releases, are read-only.

**DRAFT_READY** means every required asset exists and its complete downloaded
content has been verified. PR approval and Publish release remain manual;
branch protections, release-only delta evidence and optional separately approved
retirement retain their existing gates. Production acceptance requires a real
maintainer-approved future release; hermetic regression fixtures cannot prove
GitHub's upload transport in production.

The existing central `release_draft_test.mjs` entry imports the isolated asset
scenarios in `tests/support/release_draft_assets_scenarios.mjs`. This extends release regression
coverage without changing `scripts/audit_registry.php`, which the protected
integration selector requires to match inherited develop BASIC authority.
