# Maintainer checklist: feature to published release

Feature agents prepare and push only their working branch. The maintainer performs
the protected integration, release, publication and separately approved cleanup
below. BASIC alone is not FULL qualification; `plan` authorizes nothing unless
its summary explicitly says `ELIGIBLE`.

## Create the release branch

- [ ] Start from current FULL-qualified develop A. Create and push
  `release/v_X.Y[.Z]` for the intended next version.
- [ ] Wait for automatic preparation, notes, all four manuals/PDFs, exact-Q BASIC
  release delta checks and release-owned-input proof. The release delta does not
  repeat A's functional FULL matrix.

## Review and approve the release PR

- [ ] Review the bot-created PR to main: exact Q, notes, manuals and outstanding
  manual coverage. Submit **Review changes -> Approve** once.
- [ ] Automatic completion performs protected merge, immutable tag and safe
  develop synchronization. Parallel develop work may block synchronization
  independently; it never authorizes a merge, reset or rebase of develop.

## Review and publish the completed draft

- [ ] Wait for completion to report **DRAFT_READY**. Review the linked draft with
  the original production ZIP, integrity/version metadata, all four manuals,
  qualification archive, checksums, notes and both completion receipts.
- [ ] Verify the installer and `winapp-update.json` pair is present. Automation
  uses the exact qualification artifact when built, or verifies and preserves
  the pinned predecessor's pair when shipped WinApp source is unchanged.
- [ ] Review outstanding browser/PDF/manual checks, then click **Publish release**.
  There is no normal download, ZIP extraction, manual asset attachment or extra
  upload workflow. Actions artifacts remain available for diagnostics.
- [ ] On **TAGGED / DRAFT_PENDING**, read the concrete asset/provenance error and
  rerun the same completion after correcting its prerequisite. It keeps existing
  identical assets and adds missing files without changing merge/tag. Conflicts
  fail closed; published releases are never modified automatically.

## Verify Pages deployment

- [ ] Once, set **Settings -> Pages -> Build and deployment -> Source -> GitHub
  Actions**. Keep **github-pages** restricted to branch **main**, with no tag
  deployment access or bypass.
- [ ] Stable publication starts **Project website** in tag context. Its **Refresh
  published website through reviewed main** job dispatches a separate main run.
  The tag-context handoff cannot deploy.
- [ ] Wait for the new main **Project website** build, website qualification and
  **Deploy reviewed website to Pages** to succeed.
- [ ] Retry configuration/transport failures using **Project website -> Run
  workflow -> Use workflow from: main**; there are no inputs. Do not rerun the
  historical rejected tag deployment.
- [ ] Check [the live site](https://klusik.github.io/PHP_gallery/) on desktop/mobile:
  EN/CS/DE/SV, docs navigation, published version and pinned README/patch notes/PDF links.

## Retire the completed release branch

- [ ] Require published release/evidence, qualified Q retained in protected linear
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
- [ ] Record owner acceptance, e.g. an English PR comment: "I confirm this release
  is published with its required original assets, qualified Q is retained on
  develop, and I approve retirement of release/v_0.127.4."
- [ ] Paste the permalink in `manual_review`, select `retire` and run. Approve the
  waiting **release-retirement** environment job. Its server gate requires the
  sole owner, permits self-review and forbids admin bypass. Fresh validation and
  an exact SHA lease precede deletion; tags/assets/main/develop remain intact.
- [ ] After this fix reaches main, repeat for older **release/v_0.127.3** using
  that exact branch and its own acceptance. Automatic FF no longer needs the
  removed reconciliation workflow.
- [ ] Feature branch deletion is optional after its content reaches develop and
  a completed release. The retirement action handles only release refs.
