# CI and GitHub automation instructions

Read [root AGENTS](../AGENTS.md), the relevant [TESTING.md](../TESTING.md) section
and [CI_COVERAGE_REUSE_CONTRACT.md](../docs/CI_COVERAGE_REUSE_CONTRACT.md).
These rules also apply to CI/audit/evidence owners under scripts/ and tests/.
The root branch authorization and prohibition on agent develop/main writes apply
throughout. Feature edits never authorize themselves as protected CI authority.

## Current operational sequence

1. Finish source, tests, permanent docs and membership staging; commit and push
   only the explicitly authorized working branch with an exact ref and a checked
   remote-head lease. `candidate-preparation.yml` is currently **Basic source
   checks** on ordinary feature pushes: BASIC `--changed`, no preparation/FULL.
2. The maintainer's **Integrate into develop** action selects one final commit S
   directly above observed develop P and requires fresh exact-SHA BASIC. Missing
   history, unsquashed/merged/stale work or evidence blocks selection.
3. Hosted preparation generates runtime modules, inventory and manifest; publishers
   recheck source/develop leases. The reviewed automatic mode conditionally replaces
   only the selected feature commit using its exact old SHA, retains the prepared
   tree above P, then dispatches and authenticates fresh BASIC before FULL. Read-only
   candidate preflight refuses stale generated state. Bot commits do not
   recursively trigger qualification; the caller invokes the reusable matrix.
4. Apply the manual squash checkpoint below when automatic mode is disabled or
   unavailable in the installed workflow and preparation publishes a child.
   FULL qualifies the exact final one-commit candidate A before the guarded FF.
   Agents never dispatch protected integration without maintainer authorization.
5. A separately authorized release starts at qualified A, prepares B and receives
   BASIC changed-source plus release-owned-input proof for A..B; no functional
   integration replay. Follow RELEASE.md and RELEASE_LIFECYCLE.md for exact
   approval, protected merge/tag, draft and publication/recovery behavior.

BASIC remains syntax plus changed named-declaration docs. It does not cover native
headers, FULL inventory budgets or runtime regressions. Header checking in an
early gate is an unimplemented proposal, not installed coverage. Review complete
new/moved headers before committing; existing scanners are the sole rule owner.

## Squash checkpoint after preparation

After maintainer-controlled installation of the automatic mode, the integration
input `auto_squash_generated` defaults to true. It authorizes only the exact
selected feature-ref replacement: preserve the original message, record
`Prepared-From`, and bind fresh BASIC/native checks and FULL to the new SHA.
No-op preparation preserves SHA. Recovery and CI-authority updates retain their
existing paths. Failure, timeout or moved refs stop; do not retry writes blindly.
Feature source alone does not install this behavior into main.

Include every generated bot commit when counting the candidate delta. If
preparation adds a commit, stop/cancel integration before expensive FULL. Fetch
that head, preserve the complete prepared tree, and perform only the separately
authorized feature squash onto the same observed develop base. Verify tree
identity (plus explicitly reviewed source/doc fixes), exact parent, exactly one
commit above the base and clean worktree. Push with the exact old-head
`--force-with-lease`, obtain fresh BASIC and retry integration. Idempotent
preparation must retain the fresh files and SHA. Repeat if another child appears;
FULL must qualify the final candidate that integration will fast-forward.

## Workflow authoring and evidence

- `scripts/audit.php` remains the only test orchestrator; use its registries,
  profiles and explicit delegation. Do not add YAML-maintained test lists.
- Put complete native attribution headers on new shell/PowerShell, MJS/JS, YAML
  workflow copies and composite actions: Project, Repository, actual File path,
  Module Type, Purpose, Responsibilities, Author and License. Correct archived
  workflow paths too. BASIC cannot waive whole-tree/header regression contracts.
- Update every literal runtime/matrix expectation with the owner change. Keep
  strict assertions and retain failed preflight child logs in hosted artifacts;
  an exit code without the referenced diagnostic is insufficient.
- A reusable FULL job below optional ancestors needs an explicit status function
  and direct dependency success conditions. Cover skipped optional ancestors and
  adverse direct dependencies. Require published SHA = selected one-commit source
  before FULL; generated children go through the squash checkpoint.
- Bind delegated suites and every PHP shard to the same exact candidate. Require
  all native owners in aggregates and authenticated readers. Prove a complete,
  disjoint partition, preserving exclusive barriers and private resources.
  Distinguish separate runner jobs from processes sharing one runner.
- Inspect compact summaries first, `source-failures.md` once for a failed batch,
  linked JSON for full evidence, and raw logs only for FAIL/BLOCKED/material SKIP.
  Keep missing/skipped/cancelled/stale mandatory jobs distinct from success.
- Record workflow URL, exact branch/SHA/base, required owners and manual/missing
  coverage. Preserve complete original artifact bytes/provider identity and never
  fabricate missing bypass-policy inventory. Missing history/base is BLOCKED.

## Authority changes and release controls

CI runtime/owner/evaluator changes need explicit hosted maintenance qualification
and separately verified protected installation before becoming integration
authority. Use the staged develop-first procedure and exact sealed blobs/manifest
companion in the CI technical contract. Do not install through a release bootstrap
or invent W* main ancestry. Keep `authority_update` and `inherited_basic` provenance
and unchanged scanner closure. Acceptance requires real hosted evidence; verify
the present installation instead of inferring it from a feature fixture or an old
run. [Archived root CI notes](../docs/AGENT_CI_HISTORY.md) retain dated snapshots.

Before maintenance-only main installation, inspect release-origin/promotion
predecessor assumptions. Preserve the stable tag and bind any finite compatibility
bridge to the real human-reviewed merge and complete hosted evidence. Only create
a release origin after the optimization is on develop and separately authorized.
An erroneous unpublished attempt/source must be preserved in explicit archive
refs before an authorized leased branch replacement; never rewrite its origin or
evidence. Existing tags/assets/publications stay immutable; routine ref pruning
is not part of delivery.

Release lifecycle changes must read the complete RELEASE_LIFECYCLE.md contract:
human exact-candidate approval, protected merge M/tree(Q), immutable tag and
idempotent unpublished draft remain separate stages. Preserve create-only verified
assets, bounded transport retries, lost-response inventory reconciliation and
post-tag recovery without rollback. Parallel develop work blocks reconciliation,
never the completed tag. Agents do not publish, bypass policy or perform protected
writes. GitHub issue titles, bodies and every comment are always English.
