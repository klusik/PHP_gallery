# Reviewed hosted release lifecycle (issue #101)

This is the canonical operational runbook for a GitHub-hosted release. The existing
single publish implementation is `.github/scripts/release-promotion.mjs` (#100).
Preparation, post-publication reconciliation and retirement are separate state
transitions, not alternative ways to publish.

## Invariants

- Never write to `main` or `develop` directly. They must move only through a
  reviewed PR and server-enforced mandatory hosted CI.
- A release is selected from an **explicit immutable develop commit**, not
  inferred from today's moving `develop`. A newer develop HEAD is not a reason
  to rebase an already qualified candidate.
- A qualified source SHA, its generated artifact tree, previous stable tag,
  selected develop SHA and qualification workflow run/attempt must agree.
- Approval to **prepare**, **promote**, **publish**, **reconcile** and **retire**
  is separate. An agent authorization to modify a feature branch never grants
  permission to mutate protected branches or release tags.
- Exact GitHub branch/environment controls must be independently verified
  on the live repository. Example JSON or passing fixture tests do not prove
  server-side configuration.
- All historic tags/releases/qualification evidence are immutable. A new
  stabilization attempt means a new candidate and new exact-SHA qualification.

## 1. Initialize an immutable release origin (#134)

Create `release/v_X.Y[.Z]` at the **reviewed selected develop commit**. As the
very first commit on that release branch, add
`.github/release-origins/v_X.Y[.Z].json`, with these exact fields:

```json
{
  "schema_version": 1,
  "version": "0.126",
  "release_branch": "release/v_0.126",
  "selected_develop_sha": "REPLACE_WITH_EXACT_40_HEX_DEVELOP_SHA",
  "initial_main_sha": "REPLACE_WITH_EXACT_40_HEX_MAIN_SHA",
  "previous_stable_tag": "v_0.125",
  "previous_stable_sha": "REPLACE_WITH_EXACT_40_HEX_TAG_COMMIT_SHA"
}
```

The example placeholders must be replaced. Require the current `main` commit
to equal the previous stable tag commit at initialization; require that
`main` commit to be an ancestor of the selected `develop` SHA. Select a
numeric next version greater than the previous stable version. Review the
record and push this branch creation commit. The initialization commit must
have the selected develop commit as its **only parent**. A pre-existing branch
that already has stabilization commits cannot be retrospectively declared
provenanced: create a new reviewed candidate, preserving the old attempt's
evidence rather than force-rewriting it.

`.github/scripts/release-origin.mjs` verifies the exact blob is unchanged
since the initialization commit, the commit's parent equals the selected
develop SHA, previous tag has not moved, `main` still descends from the
initial published main, `develop` still contains the selected commit and
the prepared candidate descends from the initialization commit. A missing,
invalid or edited origin fails closed **before** any Copilot/TeX generation.
The qualification artifact records the source, initial main, previous tag
and origin commit and the publisher rejects missing/contradictory evidence.

## 2. Source and exact candidate qualification (#139, #140)

The release-qualification workflow runs on `release/v_*` and consists of:

1. Release branch and immutable origin validation.
2. **Stage A**, central `release-preflight`: syntax, documentation, source
   contract inventory, policy and CI workflow contracts.
3. **Stage B**, central `release-stage-b`: MVC boundaries and admin mutation
   contracts, both safe before release-generated artifacts.
4. Deterministic release preparation (patch notes, TinyTeX, manual PDFs,
   version metadata, manifests), then checkout/commit the prepared SHA with
   a non-force branch update and stale-ref rejection.
5. **Stage D**, the mandatory full hosted GitHub CI matrix and release audit
   against the **exact prepared SHA**. Required jobs cannot be replaced with
   prior quick/source preflight evidence.

First-ever target version metadata takes the immutable triggering source
commit epoch from `RELEASE_INITIAL_EPOCH`; an existing valid date is retained
without regeneration. The four manuals, metadata and `SOURCE_DATE_EPOCH`
must remain consistent. This is a *source date*, not the eventual public
release publication timestamp.

Previously reviewed release metadata may use the exact historical dotted-day
`released_label` format (for example, `3. September 2026, 13:08`).
The current undotted form and this legacy form are both accepted without
rewriting any reviewed entry; inconsistent labels remain blocked.

Stage A/B diagnostic artifacts are named `release-source-preflight` when
generation is blocked. Review their JSON/Markdown and failing suite logs.
Never fabricate a passing job, weaken debt baselines or infer that a successful
Stage A/B is a qualified release.

## 3. Guarded main promotion and publication (#133, #135, #100)

`.github/workflows/release-promotion.yml` in read-only `plan` mode verifies
an exact successful run and candidate identity. Check `main` ancestry **again**
before proposing/approving a PR. A `BLOCKED_MAIN_ADVANCED` means the current
main is no longer an ancestor of the selected qualified candidate, or changed
during inspection. Stop. Record original run, SHA, competing current main SHA
and tag, and have a maintainer decide on a **new** reviewed release candidate
with a fresh origin and all generated artifacts/CI. Do not auto-rebase,
force-move the old branch, bypass approvals, reuse a green run or rewrite a tag.

A stale-main failure retains the `release-main-recovery-inspect`
(or `release-main-recovery-approved`) artifact recording the original run,
candidate, competing main SHA/tree and required `NEW_CANDIDATE_REQUIRED`.
The original candidate becomes `SUPERSEDED` only after an explicit human
review/record of its replacement; no workflow silently declares or publishes it.

The single-owner `promote` step requires the owner's manual deployment
approval in `release-promotion`, effective protected `main` rules and
an exact, green qualification. It opens or reuses a PR authored by
`github-actions[bot]`, with head equal to the qualified release SHA. It
**does not approve or merge that PR**, enable auto-merge, bypass failed
checks or write directly to `main`. The owner `klusik` must separately
review and approve the PR and choose **Create a merge commit** manually.

GitHub may put `GITHUB_TOKEN`-created PR workflows in an
**approval-required** state. The owner first uses **Approve workflows to
run** on the PR, then verifies successful required GitHub CI and submits
a distinct PR **Approve** review. Deployment approval, workflow-run
approval and PR approval are three different decisions. The PR author
and reviewer must be different accounts; owner-authored PRs are rejected
rather than attempting self-approval.

The separately owner-approved `publish` step checks the bot PR author,
the latest valid `klusik` review **for the exact release head SHA**, the
actual merged PR commit, current `main`, identical candidate/main Git
trees and successful complete mandatory CI. Only then may it create an
immutable tag and verified draft release, upload checked SHA-256 assets,
record PR/reviewer identities in `release-evidence.json` and make the
release public. Interrupted drafts remain recoverable without duplicate
publication. The public release does not imply reconciliation completion.

**Red-check override is disabled in this owner-only implementation.**
An `override_ack` write dispatch is rejected. Failed, skipped, missing
or stale mandatory evidence must be repaired, not reclassified as green.
The read-only `plan` retains diagnostics without any merge authority.

## 4. Main-to-develop reconciliation (#136, #137)

From trusted `main`, dispatch `Reviewed release reconciliation` with
the original release branch identity. `plan` or `verify` is read-only.
The `propose` mode requires separate owner deployment approval via
`release-reconciliation` and active, strict `develop` PR/CI rules.
The bot creates `sync/release/v_X.Y[.Z]-<published SHA prefix>` from the
immutable published main tag and opens a bot-authored PR **to develop**.
It cannot merge that PR or write directly to `develop`.

After required PR workflows run (approve them manually if requested),
`klusik` submits an actual PR **Approve** review and manually chooses
a **merge commit**, retaining the previous develop history as first
parent and the exact published main SHA as second parent. The verifier
rejects owner-authored PRs, missing/dismissed/stale owner approval,
wrong source SHA, history loss, changed published notes or metadata, and
red mandatory CI. Even after GitHub merges the PR, status becomes
`RECONCILED` **only** when the published tag and merge SHA are ancestors
of current develop, the merge retains both parents and every mandatory
hosted PHP, Node, Chromium, database and packaging job passes on the
**exact resulting develop merge SHA**. Otherwise it remains
`SYNC_PENDING` or `SYNC_BLOCKED`. Do not use a reset, force push,
squash or autogenerated conflict bypass.

## 5. Optional release branch retirement (#138)

Dispatch `Reviewed release branch retirement` from `main`. Read-only
`plan` returns `ELIGIBLE`, `BLOCKED` or `ALREADY_DELETED`. Optional
`retire` requires a **separate** owner deployment approval in
`release-retirement` and a second eligibility inspection. It verifies
publication, owner-reviewed reconciliation, exact develop CI, no open
source/target PR and no active related workflows, then deletes only the
specified release ref using `--force-with-lease` against its exact SHA.
Neither tags nor permanent evidence are touched; `RETAIN` is valid.

## Required live GitHub settings: the sole human owner

The owner configured the following on **2026-10-09**, as recorded in
[#101's configuration checkpoint](https://github.com/klusik/PHP_gallery/issues/101#issuecomment-6087514464).
Both rulesets **remain DISABLED** and must not be activated by an agent:

- `Reviewed release promotion to main`, ID `24808772`, target `main`;
  required context `Release qualification`.
- `Reviewed main-to-develop release reconciliation`, ID `24808873`,
  target `develop`; required context `Complete required CI matrix`.

**Before any separately authorized activation**, the owner must turn
**Require approval of the most recent reviewable push OFF** in both
rulesets. The last pusher may be the same single human owner. Retain
one approval, **dismiss stale approvals on push**, require resolved
review conversations, allow only merge commits, strict current-base CI,
no force pushes, no deletion, and **zero bypass actors**. Check exact
required status names and the effective installed rule parameters,
not only source-controlled templates.

All three environments `release-promotion`, `release-reconciliation`,
and `release-retirement` must have exactly one required reviewer,
GitHub User `klusik`; **Prevent self-review OFF**, **administrator
bypass OFF**, wait timer OFF; and a single custom deployment branch,
`main`. They use no additional release-token secrets. The default
Actions permission remains read-only; approved writer jobs request
only specific `GITHUB_TOKEN` write scopes and reader/builder jobs get
no release write credential. Actions PR creation is enabled. Nothing
automatically approves a human PR or deployment.

This is conscious **single-owner control**, not two-human review.
The bot author and owner PR reviewer are distinct identities, but the
owner remains the only human authorization authority.

### Safe activation gate and blocked operational checks

1. Hosted feature-branch CI must pass on the exact implemented SHA.
   Keep live rulesets DISABLED during implementation.
2. The owner must separately review and integrate the updated workflows
   into default `main` and applicable `develop` history. Only then can
   dispatch from `main` execute the new code. This integration is not
   authorized to the feature-branch agent.
3. Verify actual effective environments and corrected rulesets, branch
   status contexts, approval permissions, bot identities and merge method.
4. With separate authorization, demonstrate a real controlled bot-authored
   PR, manual workflow-run authorization where required, an owner review
   and server refusal of an unreviewed/red PR. If meaningful tests require
   limited temporary protection activation, obtain explicit approval and
   document rollback. A passing fixture does **not** prove this.
5. After verifying real compatibility, request explicit owner approval
   **before activating** rulesets. Read back both effective rulesets
   immediately afterward, then use a separately authorized qualified
   release for the live end-to-end proof required by #133.

If any required REST permission, environment readback, review identity,
eligible PR check, hosted CI or qualified candidate is missing, report
**BLOCKED** with concrete evidence. Do not invent passes, bypass
protections or enable rulesets automatically.

**API visibility limitation:** The GitHub REST deployment-branch policy
listing may report only the policy ID and `name`, not its branch/tag
`type`. The runtime verifies exactly one literal `main` policy and
trusted `refs/heads/main` dispatch, rejects an explicitly reported
tag policy and requires the owner to confirm the actual branch-only
setting in GitHub Settings. The GitHub REST ruleset detail can redact
`bypass_actors` from tokens without ruleset-write permission.
The authorization check intentionally **fails closed** if it cannot
inspect the bypass inventory. If the approved job's narrow
`GITHUB_TOKEN` receives a redacted response, publication remains
BLOCKED. The owner must explicitly decide a least-privilege,
separately approved and auditable way to obtain trustworthy policy
evidence; do not automatically add an administration token or
weaken ruleset checks. Record this as an operational blocker until
tested against the real environment. GitHub documentation:
https://docs.github.com/en/rest/repos/rules and
https://docs.github.com/en/rest/deployments/branch-policies .

## Lifecycle states and recovery

| State | Meaning | Operator action |
| --- | --- | --- |
| `BLOCKED_MAIN_ADVANCED` | Main diverged during stabilization or promotion | Preserve attempt; review a new qualified candidate |
| `PUBLISHED` | Immutable public GitHub Release and assets verified | Schedule reviewed sync, not a completion claim |
| `SYNC_PENDING` | Sync PR, merged SHA or exact hosted CI is incomplete | Review/fix/qualify without force-updating develop |
| `SYNC_BLOCKED` | Missing approval, incorrect ancestry, red CI, conflict or rules drift | Stop, retain evidence, resolve explicitly |
| `RECONCILED` | Published main ancestor, owner-reviewed bot PR merge and exact full CI confirmed | Release lifecycle complete; cleanup optional |
| `ELIGIBLE` | Exact retained release branch can be retired with a lease | Request separate cleanup approval |
| `ALREADY_DELETED` | Source release ref absent, immutable publication still intact | Nothing to delete |

Log every gate in #101 with exact release branch, SHA/tree, workflow run/attempt,
CI run links, human reviewer and PR, tags/assets and remaining BLOCKED items.
Hermetic tests prove only code behavior and cannot substitute for live server
protection evidence.
