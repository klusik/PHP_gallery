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
- The new immutable version tag points to M. Develop may FF to Q, never to M.

Resolve a stable tag from actual Git history; do not invent a maintenance tag,
move a published tag or add a release-specific SHA exception. Protect origin
records and historical predecessor formats. Schema-4 initialization reads the
stable tag and release-side Git identities without replaying historical CI.
A ref race, conflicting immutable identity or different merge tree still blocks
the corresponding Git operation.

The maintainer creates/pushes `release/v_X.Y[.Z]` from current develop.
Feature implementation does not authorize release/tag/publication or protected
branch writes. Normal main review and develop linear-history protections stay
unchanged. Never change Rulesets/Environments or use a bypass.

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

The automatic observer is dispatched with the prepared release ref and exact
PR/Q/run, avoiding dependence on token-created push/PR events. It waits for the
assembly run and human review, rechecks live heads and existing server protections,
then requests the standard protected merge. Observe and verify actual M parents
and tree before tagging. Preserve the existing bounded polling/continuation and
transport retries; unknown or ambiguous write results require server readback.

Tag creation is create-only. An existing correct tag is reused; a conflicting tag
is never moved. An interrupted merge/tag/draft sequence resumes from observed
immutable state instead of rolling back or rebuilding the candidate.

## Develop synchronization, draft and attachments

After verified M/tag, a non-force FF to Q is permitted when current develop is
already Q or a true ancestor. There is no second functional qualification.
Parallel develop work reports its actual blocker without rewriting history and
does not invalidate completed tagging or draft preparation.

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
**Publish release**. Production smoke tests, Pages deployment and separately
approved branch retirement remain distinct operations.

## Acceptance and reporting

Registered fixtures cover selected feature execution, adverse mandatory owners,
sealed recovery, protected-develop source capture, release records, real Git
graphs, stale/missing review, tag conflicts, partial asset recovery and publication
boundaries. Hosted qualification is still needed for actual provider routing and
permissions. Record exact source/base/run/attempt, all required owners, repair
iterations and avoidable FULL starts. State any live/manual coverage not performed.
