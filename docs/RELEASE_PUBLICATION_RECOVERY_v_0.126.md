# Owner-only publication recovery for v_0.126

This runbook describes a single publication exception owned by
`.github/scripts/release-promotion.mjs` and
`.github/scripts/release-owner-authorization.mjs`. It publishes the already
merged release through the existing `release-promotion.yml`, using its reviewed
recovery-branch version. It does not create commits, propose a PR, merge, update
main/develop/release branches, replace tags, change rulesets or create environments.
The implementation and hosted source qualification do not authorize publication.

## Immutable scope

| Identity | Required value |
| --- | --- |
| Repository / dispatcher / approver | `klusik/PHP_gallery` / User `klusik` |
| Dispatch ref | `refs/heads/hotfix/v_0.126-publication-recovery` |
| Release / qualified branch | `v_0.126` / `release/v_0.126` |
| Q | `aef94a6688c7a437abfed3cc4bc2c29a283421bf` |
| Qualification | `38000825301`, attempt `1`, successful push qualification |
| Main M / tag target | `e0637b0e1e7aa574f7495ed8cfa92a149f1e73d3` |
| First merge parent P | `9ef4fbe54ecb4f2eaeba7d6595cabda016b50fc3` |
| Develop | `44ba56cca2f110a2aa2527da337014750aebc6a3` |
| Main and Q tree | `8dc3aa0eada8ec63343b5924543a2e867bd87383` |
| Manually merged bot PR | `#179`, owner review `5476274538` on Q |
| Environment | `release-publication-recovery-v_0.126` |

The recovery branch starts at M. Its final prepared SHA is separate tooling,
never the release payload or tag target. Review that exact SHA and its successful
Candidate preparation run before dispatch. Every job checks out `github.sha`
for tooling; all release payload/builds still use Q. The remote recovery head
must remain exactly the dispatch SHA throughout publication. Another release,
ref, actor, rerun actor, attempt, override or promotion mode is rejected.

On 2026-10-10, read-only inspection found main/develop rulesets `24808772` and
`24808873` active, no main bypass actors, PR #179 owner-approved and owner-merged,
ordered parents `[P,Q]`, identical trees and no `v_0.126` tag. These are observations,
not substitutes for the publisher's new server checks. Existing protections remain
unchanged. The original run's head SHA is its source event, while the retained
qualification record and explicitly bound checks identify the prepared Q.

## Exact check identity and regression coverage

For each required name, `Release qualification` and `Complete required CI matrix`,
select the exact `external_id`
`release:38000825301:1:aef94a6688c7a437abfed3cc4bc2c29a283421bf`.
Require exactly one such check, GitHub Actions App ID `15368`, head Q, completed
status and success. Count duplicates before validating the App or outcome.
Independent PR checks with another external identity are irrelevant to release
qualification, including red PR checks; they cannot substitute for a missing
release check. `filter=all` retains duplicate attempts instead of hiding them.
Incomplete or oversized inventories fail closed.

The central Node registry executes `hosted_release_policy_test.mjs` and
`release_publication_recovery_test.mjs`. They cover independent same-name PR
checks, duplicate release identities, foreign Apps/SHA/run/attempt, red/skipped
checks and jobs, missing jobs, owner/environment/ruleset rejection, provenance,
merge/review/tree/branch drift, checksum corruption, per-write revalidation,
interrupted drafts and retries that perform no additional writes. These fixtures
also cover complete paginated release inventory and conflicts beyond page one.
They make no GitHub writes. Hosted full qualification owns syntax and integrated tests.

## Complete merged PR detail

Publication run `38008786163` stopped before writing because the closed-PR list
does not contain `merged_by`. The complete `GET /pulls/179` response records
`merged_by.login = klusik`. The publisher now uses the filtered list solely for
unambiguous discovery, then fetches `GET /pulls/{number}` and checks the number,
node ID, closed/merged state, source/base refs, repository names, SHAs, author,
merge SHA/timestamps and evidence URL against the listing. A listed merger, when
present, must also agree. The detail proves the human merger and supplies the PR
for effective owner-review validation; list data alone cannot authorize publishing.

Central regressions model the real absent-list-merger/present-detail-merger
response. They reject a different or absent detail merger, missing identities,
conflicting list/detail data, malformed detail and discovery/detail API failures.
Recovery fixtures also prove those failures precede every server write. This
repair changes neither pinned release identities nor publication authorization.

## Create the temporary environment only after separate owner approval

In [repository environment settings](https://github.com/klusik/PHP_gallery/settings/environments),
create exactly `release-publication-recovery-v_0.126` with:

1. Required reviewers: exactly one **User `klusik`**, no team or additional reviewer.
2. **Prevent self-review OFF** so the sole owner can approve a separate deployment.
3. **Allow administrators to bypass configured protection rules OFF**.
4. Deployment branches and tags: **Selected branches and tags**; exactly one rule
   with type **Branch** and name `hotfix/v_0.126-publication-recovery`. No wildcard,
   tag policy, main policy or additional branch.
5. No new secrets, PAT, App token, bypass actor or broader repository permission.

The validator requires `can_admins_bypass=false`, one required-reviewer rule with
`prevent_self_review=false`, `protected_branches=false`,
`custom_branch_policies=true`, and exactly that branch policy. GitHub may omit
its REST `type`; the owner must verify the Branch type in Settings as well.
The inspect job reads this policy before scheduling the approved writer, so a
missing environment cannot be silently created by the deployment job.

GitHub supports [workflow dispatch with a branch ref](https://cli.github.com/manual/gh_workflow_run)
when that workflow filename already exists on the default branch. Its
[environment branch policy](https://docs.github.com/en/actions/reference/workflows-and-actions/deployments-and-environments)
matches the workflow's `GITHUB_REF`. Use the recovery ref; the main workflow still
contains the old validator. Do not modify existing environments to admit the ref.

## Later owner dispatch, not part of implementation

First record the exact final prepared tooling SHA from the handoff and confirm
the remote recovery head still equals it. Confirm M, develop, Q, the original run
attempt and tag state again. Keep the same `manual_review` value on retries.
Then, only after explicit publication approval, User `klusik` may execute:

```sh
gh workflow run release-promotion.yml --repo klusik/PHP_gallery \
  --ref hotfix/v_0.126-publication-recovery \
  -f mode=publish \
  -f qualification_run=38000825301 \
  -f candidate_sha=aef94a6688c7a437abfed3cc4bc2c29a283421bf \
  -f release_branch=release/v_0.126 \
  -f manual_review='Owner-approved v_0.126 publication recovery; PR #179 reviewed on Q; exact tooling SHA and CI recorded in owner acceptance' \
  -f override_ack=false
```

Leave `override_reason` and `accepted_failures` empty. There are no main/attempt
override inputs; those identities are pinned in code. Review the inspect/package
results and approve the separate recovery environment deployment as `klusik`.
Do not rerun the original failed main publisher or rerun the qualification attempt.

The writer receives only `contents:write`, `actions:read`, `pull-requests:read`.
It uses the canonical publisher, verifies original mandatory jobs and provenance,
PR review/merger, parents/tree, refs and checksums again before every tag/draft/
upload/publication write. It creates only the missing tag at M, a draft, missing
identical assets and the final draft-to-public transition. Permanent evidence also
records the recovery ref, tooling SHA, environment and pinned main/develop.
Existing release state and every retained asset are checked before tag creation
and every subsequent write; conflicting bytes, duplicate assets, an unexpected
prerelease or an incomplete public release cannot trigger a new Git-history write.
The existing bounded command transport reads every release page with GitHub CLI
`--paginate --slurp`; historical releases beyond the first 100 remain visible.

## Restricted job-token diagnosis and pre-approval readiness

Real publication [38010463884](https://github.com/klusik/PHP_gallery/actions/runs/38010463884)
at tooling 278cd3feeb174be04aac7e84909258b2599c6d2d passed inspect/package
and failed after owner Environment approval with the compound
“main ruleset permits bypass or targets unexpected refs” error. That run did not
retain the full ruleset response, so the original response cannot be reconstructed.
The new diagnostic records the exact currently failing original predicate.

GitHub [documents the redaction](https://docs.github.com/en/rest/repos/rules#get-a-repository-ruleset):
bypass_actors is returned only to callers with write access to the ruleset.
Contents write is not Ruleset write. Owner-account observations cannot prove the
job token sees this field. Direct complete inspection requires repository
**Administration: write** or a corresponding custom role's ruleset-management
permission; standard GITHUB_TOKEN workflow permission keys do not provide
Administration. No new credential is approved here.

publication-readiness.yml runs only on the exact recovery branch with the
writer's real GITHUB_TOKEN: Contents write, Actions read, PullRequests read,
Metadata read implicit. It has no Environment, creates no deployment, and invokes
only GET API and Git readers. It downloads the original qualification artifact
and actual verified-publication-assets retained by failed run 38010463884.
Reports retain relevant field presence/types/values, original compound predicates,
effective rules, token-visible repository permissions, original Q/jobs/checks/
provenance, PR #179/review 5476274538, ordered parents/tree, actual prepared
SHA-256 hashes, complete paginated release inventory and draft conflicts.
An owner push is admitted only for this read-only transport; it never authorizes
publication. API permission failures have BLOCKED_API_PERMISSION; unavailable
fields are not success. This diagnostic is separate from source CI.

Before any future recovery Environment deployment, release-promotion.yml
downloads that run's newly prepared package and any required installer into a
non-Environment readiness job. It calls the same production verifyWriteControls,
verifyRecoveryPublication, preparePublicationPayload and verifyPublicationInventory
used by the writer. Readiness never creates a release asset; deterministic
permanent evidence bytes remain in memory. The approved recovery writer depends
on readiness success. Failure means no Environment approval request. Every
actual recovery write repeats policy/provenance checks, frozen bytes and
release conflict validation. This change is scoped to recovery; the standard
main publisher and unrelated promotion/reconciliation paths retain their contracts.

Live diagnostic [38011890601](https://github.com/klusik/PHP_gallery/actions/runs/38011890601)
proved the sole original failing predicate was !Array.isArray(installed.bypass_actors):
the field was absent, while every other original predicate passed. The token
reported current_user_can_bypass "never" and updated_at
2026-10-09T23:02:04.422Z. Installed/effective rule parameters agreed.
Original qualification/provenance, actual prepared assets and the complete
inventory of **150 releases**, without a v_0.126 conflict, passed.

The repository permissions object describes the installation principal, not the
token grant: it returned **all five flags false**, including push/pull, despite
successful Contents/Actions/PullRequests reads. Production never interprets those
flags as token scopes. It requires complete boolean principal metadata and rejects
admin/maintain privilege. The hosted job's server setup log explicitly granted
Actions read, Contents write, Metadata read and PullRequests read. These are the
same exact declared grants as the recovery writer. REST has no complete current
token-grant introspection; actual write feasibility is still unverified and no
write probe is authorized. API denials fail closed. The complete grant is retained
and checked as hosted evidence, not inferred from repository role flags.

## Proposed owner Ruleset attestation: separate security-contract approval

This is a proposed evidence contract, not an attestation already approved by the
owner. Code cannot manufacture approval, and ordinary issue updates or
Environment approval do not activate it. Publication stays BLOCKED without
explicit valid approval when the server hides the bypass inventory.

For the single v_0.126 recovery, the owner may separately decide whether to accept
the following contract. An **unedited JSON-only owner comment on issue #133**
must be returned by the server with human User klusik, author_association OWNER,
the exact repository/issue URL, stable comment ID and equal creation/update times.
Its body must include:

- schema_version 1 and decision APPROVE_RULESET_ATTESTATION_SECURITY_CONTRACT_V1.
- repository klusik/PHP_gallery, owner klusik, ruleset_id 24808772, exact
  recovery_ref and final prepared tooling_sha.
- qualification_sha Q, main_sha M, qualification_run "38000825301",
  qualification_attempt "1" and the exact recovery environment.
- policy: the **complete owner-visible GET ruleset detail**, including ID,
  node ID, source/type, created/updated timestamps, conditions, all rule parameters,
  links, current_user_can_bypass "never" and explicit bypass_actors [].
- policy_sha256: SHA-256 of UTF-8 canonicalPolicy(policy) from the production
  authorization module. Object keys are sorted recursively; array order is retained.

The ruleset_attestation_comment input supplies that server comment ID to readiness
and any later separately authorized publication. Every verification rereads both
the comment and current Ruleset. ID/node ID, server updated_at and **all**
token-visible fields must match the owner snapshot. Server timestamps compare the exact millisecond instant because owner REST returns a timezone offset while the installation token returns UTC; the full original owner JSON is still hashed unchanged. Only documented bypass_actors
redaction and caller-specific current_user_can_bypass are excluded from comparison;
the writer itself must still report current_user_can_bypass "never". A visible
bypass list must also be empty. Effective rules must match every installed rule
parameter with no conflicting source or Ruleset identity. Missing, edited, foreign
or stale approval, policy/digest conflicts and token failures block the next write.

This changes how zero bypass actors are evidenced: a complete owner observation
plus live identity/update metadata replaces direct live visibility of the hidden
field. GitHub's API documentation does not establish a transactional lock or
formally monotonic updated_at guarantee for every hidden-policy mutation.
No live Ruleset mutation is authorized to test that assumption here. The owner
must assess and explicitly approve this security-contract change; if those
guarantees are insufficient, retain BLOCKED and require a separately authorized
solution with actual Ruleset write access. Do not introduce a PAT, privileged
automation credential, administrative bypass or presume an omitted list is empty.

Central fixtures extend the existing recovery registry entry and retain every
authorization and interrupted-draft/retry invariant. They cover missing/empty/
malformed/nonempty bypass lists, complete attestation binding/tampering, effective
rule conflicts, token denial/privilege drift, Ruleset metadata drift after a tag,
asset conflicts beyond page one and independent read-only checks after a blocker.
Mock PASS does not establish live attestation, timestamp guarantees or publication
acceptance. Operational acceptance in #101/#133 remains OPEN.

GitHub offers no transaction spanning all these APIs. Every write revalidates
the current identities; drift after a successful write can leave a tag/draft with
retained evidence, never justify rollback or tag replacement. Inspect and resolve
the cause before retrying. A completed publication still requires hosting/updater
smoke acceptance and separately authorized release reconciliation.

## Separately approved cleanup after publication

Verify the public release, every asset digest/size and permanent evidence, with
`v_0.126` still pointing to M. Archive the final recovery diff and CI/run/evidence
identities; preserve the generic validator fix for a separately authorized future
development change, without merging this main-derived recovery branch into develop.
Confirm no workflow still uses the branch/environment. Only after separate cleanup
approval, an owner may delete the recovery ref using its exact observed SHA lease:

```sh
git push origin \
  --force-with-lease=refs/heads/hotfix/v_0.126-publication-recovery:EXACT_RETAINED_RECOVERY_SHA \
  :refs/heads/hotfix/v_0.126-publication-recovery
```

Replace the placeholder with the retained final SHA; abort if the remote differs.
This optional lease deletes only that recovery branch. Remove only the temporary
recovery environment after retaining its approval evidence. Keep main, develop,
release/v_0.126, all tags/releases/assets and existing environments/rulesets intact.
