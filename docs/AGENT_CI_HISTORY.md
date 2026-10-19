# Archived root CI installation notes

This is historical evidence, not an operative agent instruction or write grant.
These notes were moved from root AGENTS at source commit
`541fa798133c5e91b4f09afe5974eee761e4a523` (2026-10-16 reorganization).
Their statements about pending/installed transitions are dated observations;
verify current protected installation and hosted evidence in
[CI_COVERAGE_REUSE_CONTRACT.md](CI_COVERAGE_REUSE_CONTRACT.md) before relying on
that state. [Root AGENTS](../AGENTS.md) and
[.github instructions](../.github/AGENTS.md)
own current agent behavior. Existing origins, tags and evidence stay immutable.

The old root mixed installed cadence, future changes and the older blanket
activation caveat. Preserve the source text below as a quotation so it cannot
silently reset the installed BASIC -> integration FULL -> release-delta cadence.
Detailed authority-transition procedures remain in the CI technical contract;
release operations remain in RELEASE.md and RELEASE_LIFECYCLE.md.

## Source snapshot

> ### Qualification lifecycle target and activation status (#185)
>
> Current installed cadence was verified by hosted integration `38741037506` and
> release delta `38742234930` on 2026-10-15: BASIC feature pushes, one
> exact-candidate
> FULL before guarded develop integration, then release-owned BASIC delta. The
> historical activation caveats below do not reset that installed behavior.
> Changes to inherited CI authority require explicit maintenance qualification
> and maintainer-controlled installation; feature edits cannot authorize
> themselves.
>
> For the CI-authority evaluator update, use the staged develop-first procedure
> in
> `docs/CI_COVERAGE_REUSE_CONTRACT.md`; do not install it through a release
> bootstrap. Stage 1 changes only the integration publisher/workflow/readers and
> root-binding adapter while preserving the complete pinned BASIC closure, then
> uses the existing main-owned FULL integration and guarded FF to establish W*.
> Stage 2 is the owner-dispatched **Integrate into develop** action from develop
> with `ci_authority_update: true`, exact stage-1 proof and W* binding,
> one-commit
> candidate S, only the six approved evaluator transition paths, unchanged
> scanner
> closure, trusted inherited BASIC inspection, FULL from W* on exact S, and
> leased
> non-force FF. Record `authority_update` and `inherited_basic`; never invent W*
> main ancestry. Keep main protections and recovery distinct. This path remains
> unqualified until real hosted runs prove both stages. Stage 1 was installed on
> develop by main-owned run `38774538606`; record subsequent acceptance in #223.
> For the finite parallel-CI transition, retain the six executable evaluator
> pins and authenticate the one exact `app/core-manifest.json` companion against
> the sealed successful maintenance source/tree/run. Compare complete
> source/base
> leaf identities before selection, publication and final integration; admit
> only
> those exact regular blobs and preserve every other inherited path.
> Only create a release origin after the optimization is already on develop.
> Preserve an erroneous unpublished attempt and its source in explicit archive
> refs
> before replacing its leased release branch; never rewrite its origin or
> evidence.
>
> When changing a CI runtime or owner matrix, update every corresponding literal
> fixture expectation in the same batch while preserving strict assertions.
> Retain
> each failed preflight child's referenced log in the hosted artifact; an exit
> code
> without its diagnostic is insufficient handoff evidence.
>
> Always give a reusable FULL job below optional ancestors an explicit status
> function and direct dependency success conditions. Require the published SHA
> to
> equal the selected one-commit source before FULL; a generated child needs a
> new
> squash and fresh BASIC. Cover both skipped optional ancestors and adverse
> direct
> dependencies in the workflow's existing contract fixture.
>
> Always load a fixture's actual source owner before its first source assertion;
> keep shared source reads above all consumers when adding earlier contract
> checks.
> Always import or fully qualify native PHP exception classes inside namespaces,
> including invalid-input branches exercised by negative fixtures.
>
> Always bind delegated suites and every PHP shard to the same exact candidate,
> require all native owners in the aggregate and authenticated evidence readers,
> and prove the shard partition is complete and disjoint. Retain each shard's
> exclusive-test barriers and private DB/HTTP/workspace resources. Distinguish
> independent GitHub runner jobs from processes sharing one runner. Qualify a
> changed CI matrix through explicit hosted maintenance, then verify its
> protected
> installation separately before using it as integration authority.
>
> The latest #185 target replaces the earlier per-child coverage-inheritance
> proposal. Feature pushes and the final squash receive BASIC checks for PHP and
> JavaScript syntax plus complete named-declaration documentation only. After
> the
> feature history has been reduced to one authored commit S on top of develop P,
> the maintainer uses the Actions **Integrate into develop** button to select
> the
> feature branch. The action refuses an unsquashed branch, runs the complete
> central FULL matrix exactly once on final candidate A, then fast-forwards
> develop
> only after FULL succeeds. Agents never mutate `develop` directly.
>
> A release branch starts at qualified A. Release preparation creates B with
> notes, all four locked-toolchain manuals/PDFs, inventory and manifest. It runs
> BASIC changed-source checks against immutable A plus proof that every change
> is
> release-owned. It does not rerun browser, database, functional, Node runtime,
> WinApp or packaging regression suites on B. Package and installer generation
> remain release outputs; generation is not regression qualification.
>
> Human approval of the release PR, protected main merge M, tag and draft
> behavior
> remain unchanged. After the release, a pure fast-forward of `develop` to Q
> needs
> no second FULL run: the lifecycle record carries FULL for A and BASIC for the
> approved release-only A..B delta. Parallel develop work still blocks that
> synchronization.
>
> This target is **not active** until its implementation is installed on main
> and
> verified by real hosted CI. Until then, follow the currently installed
> required
> workflows; source changes and local fixtures do not activate reduced
> execution.
> See `docs/CI_COVERAGE_REUSE_CONTRACT.md` for evidence and activation gates.
> The
> older proposal to authenticate and inherit per-child develop coverage on
> release
> Q is superseded and remains only as historical context there.
