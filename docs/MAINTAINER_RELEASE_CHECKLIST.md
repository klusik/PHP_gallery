# Maintainer checklist: feature to published release

Feature agents prepare and push only their working branch. The maintainer
performs
the protected integration, release, publication and historical approved cleanup
below. BASIC alone is not FULL qualification; `plan` authorizes nothing unless
its summary explicitly says `ELIGIBLE`.

## Integrate the completed feature

- [ ] In **Integrate into develop -> Run workflow**, select main or the intended
  feature in **Use workflow from**, then its source branch. Changed tests and CI
  execute from that selected revision; no preliminary main installation is
  needed.
- [ ] Require the complete exact-candidate FULL and guarded non-force develop
  FF.
  `qualify_only` seals execution without writing develop; `qualified_run` reuses
  identical candidate/tree/base after a publication failure without another
  FULL.
- [ ] For an explicitly requested CI-only installation ahead of a release, base
  a
  feature on main and dispatch **Basic source checks** with `install_ci`. Wait
  for
  all FULL owners and release audit, then approve its bot-created main PR once.
## Create the release branch

- [ ] For the main-following release model, review develop's history policy:
  admit main's reviewed merge commits while keeping deletion/non-force
  protection,
  strict Actions checks and no bypass. A remaining linear-history requirement is
  an explicit readiness blocker. This is a separate maintainer configuration
  step;
  the workflow never changes settings. Existing divergent refs need no reset for
  the next release; its source retains them before the reviewed FF joins main.
- [ ] Start from current FULL-qualified develop A. Create and push
  `release/v_X.Y[.Z]` for the intended next version.
- [ ] Wait for automatic preparation, notes, all four manuals/PDFs, manifest and
  complete attachments. Release trusts protected develop and does
  not repeat source audits or historical evidence validation.
- [ ] On **NOTES_PENDING**, download `release-notes-handoff`, give `prompt.txt`
  to any chosen AI, review its single-line JSON, then paste it into
  **Release qualification -> Run workflow -> external_notes** on the same
  release branch. The bundle README gives the exact CLI continuation when the
  main-branch UI does not yet expose the button. Large changes bypass Copilot;
  missing allowance/provider failure exports the same complete evidence.
  A pending handoff has no qualification or PR. Changed evidence needs a new
  bundle; continuation preserves PR review and manual publication.

## Review and approve the release PR

- [ ] Review the bot-created PR to main: exact Q, notes, manuals and outstanding
  manual coverage. Submit **Review changes -> Approve** once.
- [ ] Automatic completion performs protected merge, immutable tag and safe
  develop FF to the exact tagged main SHA, without a content-copy commit.
  Confirm develop = main after completion. Parallel work may block
  synchronization
  independently; it never authorizes a merge, reset or rebase of develop.

## Review and publish the completed draft

- [ ] Wait for completion to report **DRAFT_READY**. Review the linked draft
  with
  the original production ZIP, integrity/version metadata, all four manuals,
  qualification archive, checksums, notes and both completion receipts.
- [ ] Verify the installer and `winapp-update.json` pair is present. Automation
  uses the exact qualification artifact when built, or verifies and preserves
  the pinned predecessor's pair when shipped WinApp source is unchanged.
- [ ] Confirm the final **Retire release branch after verified draft and
  retained
  evidence** job reports `RETIRED` or `ALREADY_DELETED`. It runs after
  successful
  draft/evidence retention and verifies exact Q, M/tag, protected branch
  ancestry,
  complete assets and dependencies before a leased deletion. Incomplete develop
  synchronization reports `SKIPPED` and retains the branch. On cleanup failure,
  retry only that job; original draft preparation and manual publication remain
  valid, and immutable-SHA retries handle an already deleted ref.
- [ ] Review outstanding browser/PDF/manual checks, then click **Publish
  release**.
  There is no normal download, ZIP extraction, manual asset attachment or extra
  upload workflow. Actions artifacts remain available for diagnostics.
- [ ] On **TAGGED / DRAFT_PENDING**, read the concrete asset/provenance error
  and
  rerun the same completion after correcting its prerequisite. It keeps existing
  identical assets and adds missing files without changing merge/tag. Conflicts
  fail closed; published releases are never modified automatically.
  Transient transfers already receive up to three attempts; a lost
  draft-creation
  response is reconciled automatically. Keep the release unpublished until
  **DRAFT_READY** so completion can safely add the remaining files.

## Verify Pages deployment

- [ ] Once, set **Settings -> Pages -> Build and deployment -> Source -> GitHub
  Actions**. Keep **github-pages** restricted to branch **main**, with no tag
  deployment access or bypass.
- [ ] Stable publication starts **Project website** in tag context. Its
  **Refresh
  published website through reviewed main** job dispatches a separate main run.
  The tag-context handoff cannot deploy.
- [ ] Wait for the new main **Project website** build, website qualification and
  **Deploy reviewed website to Pages** to succeed.
- [ ] Retry configuration/transport failures using **Project website -> Run
  workflow -> Use workflow from: main**; there are no inputs. Do not rerun the
  historical rejected tag deployment.
- [ ] Check [the live site](https://klusik.github.io/PHP_gallery/) on
  desktop/mobile:
  EN/CS/DE/SV, docs navigation, published version and pinned README/patch
  notes/PDF links.

## Retire a retained historical release branch

Current synchronized schema-4 releases clean their own branch automatically
after `DRAFT_READY`. Use this separate reviewed path for historical/retained
refs;
it does not change its published-release prerequisite or environment approval.

- [ ] Require published release/evidence, qualified Q retained in protected
  linear
  develop and no open dependent PR or active release qualification/promotion.
- [ ] Open **Reviewed release branch retirement -> Run workflow**:

| Field | Planning run | Deletion run after `ELIGIBLE` |
| --- | --- | --- |
| Use workflow from | `main` | `main` |
| release_branch | `release/v_0.127.4` | the same exact branch |
| mode | `plan` | `retire` |
| manual_review | empty | permalink to owner's explicit cleanup acceptance |

- [ ] Run `plan`; require **ELIGIBLE** and its expected SHA. `BLOCKED` or errors
  require fixing the stated prerequisite, not deleting manually as a substitute.
- [ ] Record owner acceptance, e.g. an English PR comment: "I confirm this
  release
  is published with its required original assets, qualified Q is retained on
  develop, and I approve retirement of release/v_0.127.4."
- [ ] Paste the permalink in `manual_review`, select `retire` and run. Approve
  the
  waiting **release-retirement** environment job. Its server gate requires the
  sole owner, permits self-review and forbids admin bypass. Fresh validation and
  an exact SHA lease precede deletion; tags/assets/main/develop remain intact.
- [ ] After this fix reaches main, repeat for older **release/v_0.127.3** using
  that exact branch and its own acceptance. Automatic FF no longer needs the
  removed reconciliation workflow.
- [ ] Feature branch deletion is optional after its content reaches develop and
  a completed release. The retirement action handles only release refs.
