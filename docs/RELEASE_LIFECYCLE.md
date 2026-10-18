# Automatic reviewed release lifecycle

## Qualification boundary

The owner-selected **Integrate into develop** workflow owns complete source
qualification. The GitHub workflow-ref selector may select main or a feature.
That immutable revision supplies the workflow, central audit and tests, including
changed fixtures/registries. Explicit owner dispatch, fresh BASIC, complete FULL,
candidate/base leases and server branch protection authorize the eventual FF.

Protected develop is the authoritative qualified source. Release assembly does
not repeat BASIC/FULL, inspect old evaluator versions, require archived
integration ZIPs or requalify earlier releases. Test failures belong before
develop admission. Missing output, compilation or hashing failures still stop
assembly because an incomplete package cannot be delivered.

## Source and Git identities

- A is the immutable develop commit selected when the release branch is pushed.
- O introduces the immutable origin/predecessor records immediately after A.
- Q/B is the prepared release branch head, including metadata, notes and manuals.
- P is actual observed main when O is initialized. The preceding stable tag may
  be on an earlier protected first-parent merge after CI-only maintenance.
- M is the reviewed standard merge with parents [P,Q] and tree(Q).
- The new immutable version tag points to M. Unchanged develop A fast-forwards
  to that exact M. Both branch refs then match; no synchronization commit is made.

Resolve a stable tag from actual Git history; do not invent a maintenance tag,
move a published tag or add a release-specific SHA exception. Protect origin
records and historical predecessor formats. Schema-4 initialization reads the
stable tag and release-side Git identities without replaying historical CI.
A ref race, conflicting immutable identity or different merge tree still blocks
the corresponding Git operation.

The maintainer creates/pushes `release/v_X.Y[.Z]` from current develop.
Feature implementation does not authorize release/tag/publication or protected
branch writes. Main review, develop's non-force/check protections and no-bypass
rules remain mandatory. The target develop policy admits reviewed main merges;
ordinary feature integration still requires one direct child of develop.
The legacy `required_linear_history` rule conflicts with main's actual merge.
Read-only readiness and schema-4 PR creation report that blocker before approval.
Only the maintainer may separately change installed policy; agents never edit
Rulesets/Environments or use a bypass.

## Feature qualification and publication recovery

Preparation runs without persisted write credentials. Generated data is
published by the owner-selected workflow with exact source/base leases. Keep one
final source commit above observed develop, dispatch fresh BASIC after a prepared
rewrite and execute the complete 28-owner matrix on final A.

The read-only **Retain integration execution** stage uploads schema-2 execution
evidence before develop publication. `qualify_only` seals without a protected
write. `qualified_run` can reuse identical candidate/tree/base execution after a
provider publication failure. Every original owner, owner-selected workflow,
run/attempt and independent report/archive binding must remain valid. Changed
inputs or cancelled/superseded execution invalidate reuse.

A CI-only branch based on main can use **Basic source checks → install_ci**.
This reusable qualification executes FULL plus the authoritative release audit,
retains all 29 native owners, checks source/main leases and CI/test/docs scope,
and opens a bot PR. A real human review and protected auto-merge install it.
No throwaway workflow or current-version identity table is required.

## Assembly and schema 4

Preparation freezes A, updates runtime/release metadata and all four manual
edition markers, and preserves or generates editorial patch notes. It compiles
all four PDFs through the locked TinyTeX/package toolchain using one reproducible
release timestamp. Production inventory precedes final complete-file manifest
generation. Conditional installer generation preserves the separate WinApp version.

Patch-note evidence uses the complete Git text diff, bounded UTF-8 chunks,
SHA-256 manifests and bounded provider requests. Invalid/missing AI output is
retained safely and never applied. Existing complete maintainer prose is reused.
Generation does not invent product behavior or rewrite technical manual content.

Assembly commits only intended release files back to the same leased branch.
Before review it links frozen P as a parent while retaining the complete prepared
tree. The PR presents that exact tree against P, including any changes from main
maintenance, before the owner approves. Main drift stops publication.
Lifecycle schema 4 records `protected-develop-v1`, A/tree, Q, actual Git origin
and assembly outcome. There is no synthetic BASIC delta or invented inherited
test PASS. The required `Release qualification` check represents successful
assembly. Functional regressions were qualified before A entered develop.

Historical integration schema 1 and release schemas 1–3 retain their original
parsers and proof rules for old records. Those historical requirements do not
become gates for a new schema-4 release. New initialization must not depend on
expired Actions artifacts from a preceding release.

## Approval and automatic completion

After assembly/assets succeed, open/reuse the exact bot PR to main. The owner
submits one real approval of Q. Stale reviews, changed Q/P or a closed unmerged PR
stop the operation. The workflow never approves itself.

The automatic observer is dispatched only after assembly with the prepared release ref and exact
PR/Q/run, avoiding dependence on token-created push/PR events. It waits for the
assembly run and human review, rechecks live heads and existing server protections,
then requests the standard protected merge. Observe and verify actual M parents
and tree before tagging. Preserve the existing bounded polling/continuation and
transport retries; unknown or ambiguous write results require server readback.

Tag creation is create-only. An existing correct tag is reused; a conflicting tag
is never moved. An interrupted merge/tag/draft sequence resumes from observed
immutable state instead of rolling back or rebuilding the candidate.

## Develop synchronization, draft and attachments

After verified M/tag, schema 4 requires unchanged selected develop A to be an
ancestor of M, then advances develop directly to M with `force:false`. It creates
no commit and does not accept an equal-content sibling as synchronized. The
required Actions status on M represents the verified assembly, reviewed tree and
qualified source ancestry, not another FULL. Status identity and all three ref
leases are rechecked before the protected FF and develop is read back afterward.
The permanent receipt records `RECONCILED_FF`, M/tree/A. A retry accepts actual
develop = M without duplicate status/ref writes; races stop publication.
Parallel develop work reports its actual blocker without overwriting it and
does not invalidate completed tagging or draft preparation. Historical releases
retain their original linear-Q FF and reconciliation readers. Published schema-4
`RECONCILED_EQUIVALENT` receipts still validate their original single-parent result
and retained ancestry; new completion never emits that content-copy format.

Existing divergent synchronization commits are not rewritten automatically. A
later release whose source retains current develop can join both histories by
FF after the maintainer installs the compatible history policy. Immediate
realignment is a separately authorized migration. Tags, published main history
and existing receipts remain immutable.

New release initialization trusts its protected develop source even when the
preceding Q is not its Git ancestor. It does not demand an old reconciliation
attachment or replay historical CI. Stable tag/origin identities remain checked.
Successful origin Git push is acknowledged by Git itself; a cached immediate
REST ref response cannot turn it into a failure. Later publishers retain leases.
Early approval is observed by the post-assembly observer; a second review-event
job no longer fails merely because assembly is still pending.

The filler inventories all releases/drafts and original uploaded assets before
writing. It authenticates original archive bytes and each attachment's digest,
adds missing files, preserves identical assets and refuses conflicting ones.
Lost create/upload responses are reconciled through complete inventory readback.
A published release is read-only.

Required attachments include production ZIP, inventory, manifest, metadata, all
four PDFs, checksums, notes and permanent assembly/completion evidence. A required
installer comes from this assembly; unchanged companion inputs use the preserved
verified installer/update pair. Never replace an old published installer.

Only complete verified assets permit **DRAFT_READY**. **DRAFT_CREATED** and
**TAGGED / DRAFT_PENDING** are intermediate states. The maintainer manually clicks
**Publish release**. Production smoke tests and Pages deployment remain distinct.

## Automatic ready draft branch retirement

The final completion job runs only after `DRAFT_READY` and successful retention
of the original `release-completion-record` artifact. Its inventory records each
byte-verified attachment's name, size and SHA-256 digest. The cleanup job uses
immutable Q and that same-run artifact; it does not need a mutable branch to
recover an already accepted deletion. Retry only the failed cleanup job.

Current schema-4 completion requires `RECONCILED_FF` and the permanent receipt
binding actual M/tree/A. Missing synchronization skips deletion and retains the
branch. Before deletion, independently authenticate assembly and exact owner
review, [P,Q]/tree(Q), immutable tag, M ancestry in current main and develop,
the complete uploaded draft inventory, no dependent open PRs and no other active
release operations. Exclude only the authenticated current completion run from
activity checks. Changed refs, unknown/incomplete inventories or failed proof
block cleanup without undoing successful draft preparation.

Delete only `refs/heads/release/v_X.Y[.Z]` with an exact Q Git lease and read the
remote ref afterward. A lost write response followed by confirmed absence is
success; a surviving or advanced ref stays blocked. Never repeat an ambiguous
delete blindly. Published releases, tags, protected refs and original assets are
unchanged. Manual publication needs the tag and draft, not the release branch.
Legacy/manual retirement retains its separate publication and approval controls.
No historical refs are swept. This job executes from the first qualified release
Q containing it; registration of the existing completion workflow on main suffices.

## Acceptance and reporting

Registered fixtures cover selected feature execution, adverse mandatory owners,
sealed recovery, protected-develop source capture, release records, real Git
graphs, stale/missing review, tag conflicts, partial asset recovery and publication
boundaries. Hosted qualification is still needed for actual provider routing and
permissions. Record exact source/base/run/attempt, all required owners, repair
iterations and avoidable FULL starts. State any live/manual coverage not performed.
