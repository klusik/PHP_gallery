# Linear hosted release lifecycle (issue #101)

This is the canonical operational runbook. Preparation, promotion, publication,
linear reconciliation and optional retirement have separate authorization gates.
The publisher remains `.github/scripts/release-promotion.mjs`; every audit uses
`scripts/audit.php`. No agent writes `main` or `develop`.

## History and immutable identities

A completed feature/fix is squashed into one logical commit on an unqualified
working branch and qualified again at the resulting SHA. The owner integrates it
into `develop` with a real non-forced fast-forward. GitHub **Rebase and merge**
may rewrite SHAs and is not this operation. Existing historical merges are retained;
new development commits must be linear. Concurrent feature commits must survive.

For each release, distinguish:

- `D0`: explicitly selected develop commit; the immutable origin's sole parent.
- `Q`: exact prepared, fully qualified release-side candidate.
- `P`: previous published main merge commit, obtained by peeling its stable tag.
- `Qprev`: previous release-side candidate, the second parent of `P`.
- `M`: new published main commit, with exactly ordered parents `[P,Q]`.

Require `tree(P)=tree(Qprev)` and `tree(M)=tree(Q)`. Tags point to published main
commits; `git log --first-parent main` is the publication history. A previous
published main merge does **not** need to be an ancestor of `D0` or `Q`.
Annotated tag object SHA, peeled commit SHA and tree SHA are independent identities.
No workflow rebases qualified candidates, rewrites tags or updates protected refs.

## Immutable origin and the v_0.125 predecessor bootstrap

Schema version 1 remains supported, unchanged. The first release commit introduces
`.github/release-origins/v_X.Y[.Z].json`, and its only parent must be the exact
`selected_develop_sha`. Its fields remain `schema_version`, `version`,
`release_branch`, `selected_develop_sha`, `initial_main_sha`, `previous_stable_tag`
and `previous_stable_sha` (the peeled published commit). The blob cannot change,
be deleted/reintroduced or be retrospectively attributed to another source.

New initializations introduce `.github/release-predecessors/v_X.Y[.Z].json` in the
same origin commit. This immutable supplemental record pins the predecessor tag
object/type, peeled main commit, release-side candidate, tree and evidence kind.
Qualification regenerates **evidence**, not origin files, and checks every identity
against the server. The source comparison base is `Qprev`; comparing its tree to
`P` proves the same published baseline without incorrectly imposing ancestry.

The locally initialized `v_0.126` origin commit
`af3b8bfb03d5ddf5eea5bd5c8d4a237df0893460`, directly above
`44ba56cca2f110a2aa2527da337014750aebc6a3`, remains unchanged. Its schema v1
exception uses the separately reviewed `.github/release-legacy/v_0.125.json`:

| Identity | Exact SHA |
| --- | --- |
| Annotated tag object | `e84b66ac93fb6bbf61fd0a599e0c34cb43484d48` |
| Published main `P` | `9ef4fbe54ecb4f2eaeba7d6595cabda016b50fc3` |
| First parent | `d92c464ad8292c8a6070f14dffaefe63b9c25051` |
| Release-side `Qprev` | `a9f08375881bae68b99aa048e3d44e428551d31b` |
| Identical published/candidate tree | `52a9d282b400f31b947967975206a216086064d7` |

This is the explicit `UNKNOWN_LEGACY` historical qualification exception, owned
by `klusik`, confined to this repository and predecessor. The historical Release
has no new publisher evidence asset. No historical approval, CI qualification or
usage measurement is invented. Future predecessors require permanent qualified
release evidence and retained release-side ancestry or verified equivalent
reconciliation. See [#101](https://github.com/klusik/PHP_gallery/issues/101) and
[#133](https://github.com/klusik/PHP_gallery/issues/133).

## Hosted preparation and exact qualification

The existing release workflow verifies branch, selected develop, unchanged origin,
current main predecessor, published stable tag and release-side/tree identities
before Stage A (`release-preflight`) and Stage B (`release-stage-b`). Both central
source stages precede Copilot, TinyTeX and generated artifacts. Stage D retains the
complete PHP/Node/WinApp, Chromium, database, runtime, packaging and release audit.

There is no implicit `git describe` selection. Audits and commit-note ranges use
verified `Qprev`; file differences, release-note text and WinApp scope use direct
endpoint tree comparisons. GitHub compare ancestry and merge-base file lists
cannot substitute for a complete endpoint diff. The release-note helper receives
`PHP_GALLERY_RELEASE_BASE` and verifies its tree equals the explicit previous tag.
Its direct CLI tag-based default remains supported for historical maintainer use.

The locked TinyTeX toolchain, EN/CS/DE/SV PDF builds and final SHA-256 integrity
remain unchanged. Missing first-trigger metadata uses the immutable source epoch;
valid existing metadata is retained. Identical preparation may reuse its exact
existing tree/SHA; any differing remote-head race blocks a normal write-back.

Qualification binds the exact emitted prepared SHA, comparison base, origin commit
and blob, predecessor identities, run/attempt, all required jobs and source record.
A later edit or squash requires new qualification. The aggregate result also binds
`Complete required CI matrix` to every exact prepared SHA, including bot-generated
commits, so a protected owner FF has the required context on its destination.
This check is derived from the complete actual matrix; it is never a red override.

## Promotion and separate publication

Normal `plan`, `promote` and `publish` dispatch from reviewed `main` tooling.
`promote` requires owner deployment approval and effective main protections, then
opens/reuses a `github-actions[bot]` PR at exact `Q`. It never approves or merges.
Require current main to remain exactly `P`, the previous tag object/commit/tree
and `Qprev` to remain pinned, and every exact qualification job to be green.

The owner authorizes PR workflow runs if GitHub requests **Approve workflows to
run**, submits an actual SHA-bound PR **Approve**, and chooses **Create a merge
commit**. Missing, dismissed, stale or wrong-identity reviews are rejected.
Publication is a new owner-approved dispatch. It verifies the actual merge's ordered
parents `[P,Q]`, current main `M`, identical full tree, unchanged origins/predecessor,
qualification, assets and final refs before tagging and draft-to-public publication.
A main advance is `BLOCKED_MAIN_ADVANCED`; retain recovery evidence and obtain an
explicit new-candidate decision. Do not automatically rebase, update or publish.
No write mode accepts red/skipped/cancelled/missing CI or an override.

Promotion rechecks the current GitHub Actions `Release qualification` and
`Complete required CI matrix` contexts on Q, including their explicit
`external_id` binding (`release:<run>:<attempt>:<SHA>`). GitHub Actions normalizes
the check's `details_url` to its own `/runs/<check>` URL; it is a navigation field,
not an immutable run binding. Candidate checks use the corresponding `candidate`
binding. Reconciliation decodes that identity and rechecks actual server-owned
run/attempt and every central matrix job, then records the canonical Actions URL.
Missing, foreign or stale external identities fail closed.
The hosted gate exposes the unprefixed matrix context after the central aggregate
passes, even when preparation leaves SHA unchanged; reusable job names may carry
caller prefixes. This derives from the central registry and does not replace any
matrix job. The publication PR must have no auto-merge configuration and its
server-recorded merger must be the human owner. Unknown or overlapping effective
branch rules fail closed pending a live compatibility review.

Existing tags never move. Interrupted drafts reuse only identical assets, sizes
and SHA-256 digests. Conflicting existing bytes block rather than clobber. Release
evidence retains candidate/main/tree, origin and predecessor, review ID/SHA/time,
run/attempt, comparison base and actual outcomes. Hosting/updater smoke acceptance
remains separate.

## First-release PR-only bootstrap

The older publisher is currently on `main`, while the corrected publisher arrives
with `v_0.126`. Never dispatch the old promote/override path or add an intermediate
untagged feature commit to main. The already-present workflow filename
`release-promotion.yml` supplies the new `bootstrap` mode **at the exact qualified
release ref**. It is limited to an owner dispatch for `v_0.126` with the pinned
legacy predecessor, fully green qualification and explicit review reference.

Its job has `contents: read`, `actions: read`, `pull-requests: write`; it can only
propose the bot-authored PR. It has no protected-ref/tag/publication write authority.
Main-only deployment environments cannot approve a release-ref job, so this
proposal uses explicit owner dispatch; it cannot authorize the later merge or
publication. The owner reviews the exact candidate workflow before dispatching.

GitHub requires the dispatched workflow filename on the default branch; it already
exists here. Ref-specific bootstrap routing, bot PR creation, manual workflow
approval and required-check eligibility still need the separately authorized live
acceptance in #133. If GitHub rejects this routing or review policy, stop at
`BLOCKED` and record the actual server limitation. Do not substitute the old
publisher, an unrestricted credential or automatic merge. After the owner manually
merges `[P,Q]`, the corrected publisher is on main and a separate protected
`publish` dispatch may proceed. No publication occurs during proposal.

## Linear reconciliation

Publication is `SYNC_PENDING`. From main, owner-approved `propose` can prepare only
a `feature/reconcile-v_X.Y-<develop prefix>` working branch and dispatch existing
candidate preparation. It never pushes develop or creates a mandatory develop PR.

When develop has not advanced, the owner proposes the genuine non-force FF
`D0 -> Q`, retaining Q SHA and tree. No additional commit is needed. Exact release
qualification and the real required matrix check must already exist on Q.
The verifier checks the exact pre-FF develop-to-Q range for merge commits and
retained selected history; active server rules alone are not content/history proof.

When develop advanced to `D`, compute the entire three-way merge with **explicit
base D0**, parallel develop `D`, and released `Q`. Prepare a single-parent `L` whose
only parent is `D`. This retains all parallel feature history and release changes,
including deletions, mode changes and binary files. Compare the complete actual L
tree against the expected tree, and retain SHA-256 of the full binary/full-index
`D0 -> Q` and `D -> L` patches. Only runtime modules, production inventory and core
manifest may differ as centrally verified regenerated artifacts; those paths are
explicitly recorded in the proof. Same version, notes or metadata alone prove
nothing. New develop merges, lost ancestry and other tree differences block.

Conflicts are never resolved automatically with `ours`. The owner must explicitly
review **every** conflicted path, recording base/develop/release/result `ls-tree -z`
entries (mode/blob/path or empty for deletion), a concrete decision and review
reference in `resolutions_json`. The verifier recomputes the full merge and rejects
missing, extra, mismatched or unbound decisions. A decision accepting a semantic
change is human acceptance, not proof of text-equivalence. The exact resolved L
still requires full hosted CI. Complex directory/rename conflicts may require a
new reviewed source arrangement; unsupported proof remains blocked.

If candidate preparation adds generated commits, the owner squashes only this
unqualified reconciliation working branch into a single-parent L over D, then
runs hosted preparation/CI again on **that exact L**. Do not squash/rewrite release
origin history or reuse a pre-squash run. Before the owner FF, inspect fresh develop
D and result L; a concurrent develop advance requires a new plan and new CI.

After the separately authorized owner FF, `verify` requires deployment approval,
exact `result_sha`, prior `develop_base_sha`, current retained history, successful
SHA-bound central CI and full content evidence. It uploads immutable
`release-reconciliation.json` to the published release, without modifying develop.
The owner run must finish successfully before any later inspection accepts it.
Repeat verification reuses valid identical evidence; it cannot replace an existing
proof. The state is:

| State | Meaning |
| --- | --- |
| `SYNC_PENDING` | Owner FF, accepted evidence or exact completed CI is incomplete |
| `SYNC_BLOCKED` | Conflict, lost content/history, invalid SHA/tree/owner or server policy |
| `RECONCILED_FF` | Exact Q retained after the owner FF, with complete CI and owner audit |
| `RECONCILED_EQUIVALENT` | Single-parent L retained, complete three-way/patch proof and exact CI |

The tagged main merge does not need to be an ancestor of develop. A later release
accepts Q lineage or recomputes the durable equivalent reconciliation proof and
checks its successful owner workflow. Preserve immutable evidence on interruption;
an asset uploaded by a subsequently failed owner run is blocked and requires an
explicit recovery decision, never silent replacement.

## Server controls and live acceptance

Rulesets `24808772` (main) and `24808873` (develop) stay **DISABLED** during
implementation. Source templates do not activate them or prove server enforcement.
The compatible policies are separate:

- Main: owner-reviewed PR, one approval, dismiss stale approvals, resolved review
  threads, merge commits only, no force/deletion/bypass; **loose** required
  `Release qualification` from GitHub Actions (`integration_id=15368`). Strict
  up-to-date would incorrectly require previous M ancestry in Q.
- Develop: linear history, non-force/non-deletion, **no required PR**, strict
  `Complete required CI matrix` from GitHub Actions. The owner alone performs
  actual FF; a PR rebase merge is not an SHA-preserving integration method.

All three existing environments require sole User `klusik`, Prevent self-review
OFF, admin bypass OFF, exactly one branch-only `main` deployment policy. Missing
bypass inventory, incomplete effective rules or unknown policy remains fail-closed.
No implementation step changes GitHub settings or broadens credentials.

GitHub's [required-check documentation](https://docs.github.com/en/pull-requests/how-tos/merge-and-close-pull-requests/troubleshooting-required-status-checks)
excludes checks created by workflow jobs triggered only by `workflow_dispatch`
from required PR checks; the external GitHub App exception is not evidence that
an Actions-token-created check is eligible. Promotion therefore returns
`BLOCKED_PR_CHECK_ELIGIBILITY` for a dispatch-only qualification. After automatic
initialization/preparation, the owner must review a finalization commit (an explicit
empty acceptance commit is possible), push it normally to the release branch and
obtain fresh full qualification on its exact SHA from the **push** event. No
qualified candidate is rebased or silently rewritten. A future eligible-event/App
bridge requires a separate architecture decision and live acceptance; there is
no automatic credential fallback. The current v_0.126 qualification should start
with the separately approved owner push of its existing release branch.

We statically verify exact context names,
GitHub Actions provenance and emitted candidate SHA. Live bot PR check eligibility,
non-ancestral Q, loose main policy, actual merge parents/tree and protected owner FF
must be tested before activation/publication. No synthetic fixture establishes
that compatibility. See [workflow triggering](https://docs.github.com/en/actions/how-tos/write-workflows/choose-when-workflows-run/trigger-a-workflow)
for ref dispatch and bot PR workflow approval.

The read-only server inventory on 2026-10-09 confirms Actions PR creation/review
permission is enabled. Both disabled ruleset REST responses currently omit
`integration_id` for required checks. Protected write guards require the explicit
GitHub Actions binding (`15368`) and fail closed if that provenance is absent.
Only the owner may later decide and authorize compatible activation/binding and
the live PR test; this implementation changes neither setting.

## Start New Release and optional retirement

After the corrected workflows reach main through the first release merge,
**Start New Release** accepts version and optional exact develop SHA. It requires
owner dispatch and separate main-only deployment approval, checks the current
published main and immutable predecessor, selected develop lineage, target absence
and fresh ref leases, then creates one origin commit containing both records and
atomically creates its release ref. It explicitly dispatches existing release
qualification because token-created pushes do not recursively start Actions.

Initialization refuses a selected develop tree already containing that version's
origin/predecessor paths or missing the qualification handoff contract. No existing
initialization bytes can be overwritten through atomic tree creation.

An interrupted run may leave unattached Git objects; it cannot publish a partial
origin branch. A retry reuses only an unchanged verified origin and source. A
conflicting branch/tag/source or a raced main/develop/tag blocks. There is no PATCH
ref, force update, protected write, tag or publication operation. `v_0.126` is
explicitly excluded because its existing local origin must be transferred unchanged.

GitHub's ref-creation API cannot atomically lease other branch/tag refs. Initialization
therefore rechecks the target tag and selected context immediately before creation
and again before qualification handoff. A detected race after creation fails the run
and retains the exact origin branch for explicit owner recovery; it never moves or
deletes that origin. Qualification independently rechecks the immutable context.

Retirement accepts only `RECONCILED_FF` or `RECONCILED_EQUIVALENT`. It separately
requires owner approval, exact candidate lease, no active source/target PR or
related workflow, and fresh eligibility. The retained release branch is optional;
keeping it is valid. Deletion targets only that one leased release ref. Historic
tags, releases and proof files remain immutable.

## Current v_0.126 implementation boundary

Implementation is authorized only on `feature/linear-release-lifecycle-101`,
starting at the existing local release HEAD. The origin commit stays outside the
squashable implementation range. After review, the owner may separately authorize
fast-forwarding local release to the exact hosted-qualified feature candidate;
no release integration, release-branch push, tag, ruleset activation or publication
is part of this implementation task. P0 source correctness is demonstrated by the
central hosted matrix; #133 live server and publication acceptance stays blocked
until separately authorized. Stage A/B failure timings, cold/warm PDF cost and
live first-trigger idempotence remain operational measurement gaps (#139/#140).
