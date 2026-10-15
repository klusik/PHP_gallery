# Reviewed maintenance repair of the integration dispatcher

The owner explicitly authorized this maintenance qualification and PR on
2026-10-15. It repairs the FULL job in the integration workflow installed on main
`d38443c355a2b2e14cbfaa1757e9fde4448f8fbb` (workflow blob
`cdf9548af2b59c9499e07e5156adcd6f76a77a5c`). Ordinary integration skips the optional
inherited-BASIC ancestor; the missing explicit status condition then suppresses
FULL through GitHub's dependency chain. Runs `38792897295` and `38796227139`
confirmed the failure even when preparation left the source SHA unchanged.

The repair explicitly requires a non-cancelled run, successful selection and
candidate publication, and candidate/source SHA equality. The publisher still
requires every native FULL owner. A newly generated child stops before the
expensive matrix until the complete feature delta is squashed and receives fresh
BASIC. The existing registered integration fixture evaluates the actual YAML
condition for both optional-BASIC outcomes, adverse direct dependencies,
cancellation and the generated-child checkpoint.

## One-time installation

The repair branch is `feature/ci_main_workflow_repair`, with one commit above the
observed main. Its only changes are the dispatcher, its registered regression
fixture and this permanent explanation. Product files, audit evaluators,
scanners, owner matrices, release markers, manuals/PDFs and generated artifacts
remain identical to that main.

The separate `feature/ci_main_workflow_qualification` branch adds a transport
workflow outside the repair PR. Its immutable source pins the repair commit,
tree, complete path/blob delta and observed main. It invokes the existing
`gallery-workflows.yml` at the full immutable main SHA with `audit_profile:
release`, explicit repair checkout and explicit main comparison base. All eleven
native FULL owners plus the authoritative central release audit must actually
succeed. The transport authenticates the current run/attempt and complete native
job inventory, rechecks branch/base leases and the installed/effective main
rules, and retains its exact-candidate record before publishing the required
`Release qualification` check. Its separate `maintenance:` binding distinguishes
this exception from production release evidence.

Only then does GitHub Actions open the repair PR as `github-actions[bot]` and
request GitHub's normal merge-commit auto-merge. The existing main ruleset still
requires one human approval and the Actions qualification check, dismisses stale
reviews, and forbids bypass. The sole human owner can approve the bot-authored
PR; neither agent nor workflow submits an approval or directly updates main or
develop. No release origin, version, tag, asset or publication is created here.
If the pinned identities change, qualification must be repeated for the new
candidate; old evidence is insufficient.

This is a finite repair for the observed installation, not a general recovery
input or release-before-develop bootstrap. After the protected merge, preserve
the run/artifact/PR evidence in issue #223, retire the two temporary branches
with exact leases once unused, and resume the existing staged develop
integration followed by normal `v_0.132.9` release preparation. The four manuals
for the CI optimization are maintained on its feature branch and rebuilt during
that normal release. Browser/PDF/manual live acceptance and publication remain
separate from automated maintenance qualification.
