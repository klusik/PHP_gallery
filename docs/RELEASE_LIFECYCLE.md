# Automatic reviewed release lifecycle

## Qualification boundary

The owner-selected **Integrate into develop** workflow owns complete source
qualification. The GitHub workflow-ref selector may select main or a feature.
That immutable revision supplies the workflow, central audit and tests,
including
changed fixtures/registries. Explicit owner dispatch, fresh BASIC, complete
FULL,
candidate/base leases and server branch protection authorize the eventual FF.

Protected develop is the authoritative qualified source. Release assembly does
not repeat BASIC/FULL, inspect old evaluator versions, require archived
integration ZIPs or requalify earlier releases. Test failures belong before
develop admission. Missing output, compilation or hashing failures still stop
assembly because an incomplete package cannot be delivered.

## Source and Git identities

- A is the immutable develop commit selected when the release branch is pushed.
- O introduces the immutable origin/predecessor records immediately after A.
- Q/B is the prepared release branch head, including metadata, notes and
  manuals.
- P is actual observed main when O is initialized. The preceding stable tag may
  be on an earlier protected first-parent merge after CI-only maintenance.
- M is the reviewed standard merge with parents [P,Q] and tree(Q).
- The new immutable version tag points to M. Unchanged develop A fast-forwards
  to that exact M. Both branch refs then match; no synchronization commit is
  made.

Resolve a stable tag from actual Git history; do not invent a maintenance tag,
move a published tag or add a release-specific SHA exception. Protect origin
records and historical predecessor formats. Schema-4 initialization reads the
stable tag and release-side Git identities without replaying historical CI.
A ref race, conflicting immutable identity or different merge tree still blocks
the corresponding Git operation.

The maintainer selects the desired **Begin Release** workflow revision in
GitHub Actions and enters the version including `v_`, such as `v_0.136.2`.
Use develop for changes already integrated there but not yet released on main.
The selected workflow reads current protected develop separately, freezes A,
creates `release/v_0.136.2` and explicitly dispatches assembly on that release
ref. `GITHUB_TOKEN` branch creation does not supply a downstream push run.
The handoff supplies A and its Begin Release run/attempt; initialization binds
the actual release event/ref/SHA and authenticates the version-specific original
maintainer run through GitHub, accepting only the current in-progress or
successful attempt. The bot cannot initialize a release without that proof.
Existing tag/origin/source checks remain mandatory; no source audit is repeated.
Unchanged release refs permit retry after lost dispatch acknowledgement, and a
matching active assembly avoids duplicate dispatch. Advanced release refs are
never overwritten; resume their existing assembly directly. Feature implementation
does not authorize release/tag/publication or protected branch writes. Main
review, develop's non-force/check protections and no-bypass rules remain
mandatory. The target develop policy admits reviewed main merges;
ordinary feature integration still requires one direct child of develop.
The legacy `required_linear_history` rule conflicts with main's actual merge.
Read-only readiness and schema-4 PR creation report that blocker before
approval.
Only the maintainer may separately change installed policy; agents never edit
Rulesets/Environments or use a bypass.

## Feature qualification and publication recovery

Preparation runs without persisted write credentials. Generated data is
published by the owner-selected workflow with exact source/base leases. Keep one
final source commit above observed develop, dispatch fresh BASIC after a
prepared
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
all four PDFs through the locked TinyTeX/package toolchain using one
reproducible
release timestamp. Production inventory precedes final complete-file manifest
generation. Conditional installer generation preserves the separate WinApp
version.

Patch-note evidence keeps complete commit metadata and the full Git source diff.
Before any provider setup/request, automatic routing admits at most 100000 diff
bytes, 20 source files and 180000 prompt bytes; larger changes export external
evidence. Missing credentials, explicit external mode, provider installation or
request failure (including exhausted allowance), timeout and invalid provider
prose also produce **NOTES_PENDING**. The token's presence is not a quota check.
No billing credentials or new qualification are introduced.

This state retains the immutable origin and a 30-day Actions handoff artifact,
then ends successfully without qualification, manual builds, prepared commits,
PR creation or a waiting runner. Mandatory evidence/export and filesystem errors
still fail. The handoff contains a complete self-contained AI prompt, canonical
prompt, source diff, template, hash manifest and continuation instructions.
Generated release artifacts remain excluded from text evidence; nothing is
truncated to meet provider limits. Provider stderr and credentials are excluded.

A maintainer resumes **Release qualification** on the exact release branch with
the AI's single-line JSON in `external_notes`. Existing origins are reused;
ordinary note-continuation dispatch never initializes a release. The separate
authenticated Begin Release handoff may initialize its frozen source.
Repository/version/branch/source and the
SHA-256 of freshly rebuilt canonical evidence must match the response before
strict Markdown validation and application. Source or template drift requires
new evidence. An invalid pasted response is an input error, not another provider
fallback. Completed maintainer prose is reused. Generation does not invent
product behavior or rewrite technical manual content. See [RELEASE.md](../RELEASE.md)
for the UI and registered-workflow CLI continuation during first installation.

Pending handoffs deliberately skip the release gate and its retained success
record/check. Successful resumed assembly retains the existing exact-Q gate;
completion and draft readers authenticate both push and workflow-dispatch runs
with their unchanged workflow/repository/branch/attempt/owner/digest bindings.

Assembly commits only intended release files back to the same leased branch.
Before review it links frozen P as a parent while retaining the complete
prepared
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
submits one real approval of Q. Stale reviews, changed Q/P or a closed unmerged
PR
stop the operation. The workflow never approves itself.

The automatic observer is dispatched only after assembly with the prepared
release ref and exact
PR/Q/run, avoiding dependence on token-created push/PR events. It waits for the
assembly run and human review, rechecks live heads and existing server
protections,
then requests the standard protected merge. Observe and verify actual M parents
and tree before tagging. Preserve the existing bounded polling/continuation and
transport retries; unknown or ambiguous write results require server readback.

Tag creation is create-only. An existing correct tag is reused; a conflicting
tag
is never moved. An interrupted merge/tag/draft sequence resumes from observed
immutable state instead of rolling back or rebuilding the candidate.

## Develop synchronization, draft and attachments

After verified M/tag, schema 4 requires unchanged selected develop A to be an
ancestor of M, then advances develop directly to M with `force:false`. It
creates
no commit and does not accept an equal-content sibling as synchronized. The
required Actions status on M represents the verified assembly, reviewed tree and
qualified source ancestry, not another FULL. Status identity and all three ref
leases are rechecked before the protected FF and develop is read back afterward.
The permanent receipt records `RECONCILED_FF`, M/tree/A. A retry accepts actual
develop = M without duplicate status/ref writes; races stop publication.
Parallel develop work reports its actual blocker without overwriting it and
does not invalidate completed tagging or draft preparation. Historical releases
retain their original linear-Q FF and reconciliation readers. Published schema-4
`RECONCILED_EQUIVALENT` receipts still validate their original single-parent
result
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
four PDFs, checksums, notes and permanent assembly/completion evidence. A
required
installer comes from this assembly; unchanged companion inputs use the preserved
verified installer/update pair. Never replace an old published installer.

Only complete verified assets permit **DRAFT_READY**. **DRAFT_CREATED** and
**TAGGED / DRAFT_PENDING** are intermediate states. The maintainer manually
clicks
**Publish release**. Production smoke tests and Pages deployment remain
distinct.

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

Delete only `refs/heads/release/v_X.Y[.Z]` with an exact Q Git lease and read
the
remote ref afterward. A lost write response followed by confirmed absence is
success; a surviving or advanced ref stays blocked. Never repeat an ambiguous
delete blindly. Published releases, tags, protected refs and original assets are
unchanged. Manual publication needs the tag and draft, not the release branch.
Legacy/manual retirement retains its separate publication and approval controls.
No historical refs are swept. This job executes from the first qualified release
Q containing it; registration of the existing completion workflow on main
suffices.

## Acceptance and reporting

Registered fixtures cover selected feature execution, adverse mandatory owners,
sealed recovery, protected-develop source capture, release records, real Git
graphs, stale/missing review, tag conflicts, partial asset recovery and
publication
boundaries. Hosted qualification is still needed for actual provider routing and
permissions. Record exact source/base/run/attempt, all required owners, repair
iterations and avoidable FULL starts. State any live/manual coverage not
performed.
