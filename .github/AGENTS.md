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
2. In **Integrate into develop**, select the intended main or feature workflow ref
   in GitHub and its source branch. The owner-selected feature workflow executes
   its changed tests/CI; it needs no preliminary main installation. Select one S
   directly above observed develop P and require fresh exact-SHA BASIC.
3. Hosted preparation generates runtime modules, inventory and manifest; publishers
   recheck source/develop leases. The reviewed automatic mode conditionally replaces
   only the selected feature commit using its exact old SHA, retains the prepared
   tree above P, then dispatches and authenticates fresh BASIC before FULL. Read-only
   candidate preflight refuses stale generated state. Bot commits do not
   recursively trigger qualification; the caller invokes the reusable matrix.
4. Apply the manual squash checkpoint below when automatic mode is disabled or
   unavailable in the installed workflow and preparation publishes a child.
   FULL qualifies exact A. Seal execution before publication; `qualify_only` leaves
   develop unchanged, and `qualified_run` reuses sealed identical A/P after a
   publication failure. Changed inputs require new execution. Agents never
   dispatch protected integration without maintainer authorization.
5. A separately authorized release trusts frozen A from protected develop and
   assembles B without another source audit or historical qualification replay.
   Preserve assembly integrity, Git leases, approval, merge/tag and draft boundaries.

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

An explicit owner dispatch authorizes the selected feature workflow/test revision.
Require the actual native FULL owners and independent BASIC on the final candidate;
do not require candidate audit/registry bytes to equal main/develop. Preserve
historical `authority_update` readers for old records, not as a prerequisite for
new work. For a CI-only main installation, base the feature on main and dispatch
**Basic source checks** with `install_ci`: complete FULL plus release audit produces
a bot PR under unchanged human-review protections. Squash onto observed main
first, including bot commits; preparation must retain that SHA before FULL.
If it publishes a child, stop at the checkpoint and obtain fresh BASIC after squash.
No temporary transport or
version-specific exception is needed. [Historical notes](../docs/AGENT_CI_HISTORY.md)
never authorize protected writes.

Before CI-only main installation, preserve the stable tag and actual main base.
Release predecessor discovery follows protected main's first-parent merge history
to the existing stable tag without replaying historical tests or adding version pins.
Create release branches only when separately authorized.
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
