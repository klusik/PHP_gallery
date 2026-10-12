# Maintainer checklist: feature to published release

Feature agents prepare and push only their working branch. The maintainer performs
the protected integration, release, publication and separately approved cleanup
below. BASIC alone is not FULL qualification; `plan` authorizes nothing unless
its summary explicitly says `ELIGIBLE`.

## Restore v_0.127.3's missing permanent receipt first

- [ ] Download **release-completion-record** from successful
  [Release completion 38225091065](https://github.com/klusik/PHP_gallery/actions/runs/38225091065).
  Preserve original `release-evidence.json` and `release-evidence.sha256`.
- [ ] Verify their checksum and version `0.127.3`, PR #191, final tagged main
  `30561293711f3b0f4000bce893611e2d70751c7c` and qualified Q
  `bb4b826c667c8b79bd7edc742b948d4254184646`.
- [ ] In **Releases -> v_0.127.3 -> Edit**, manually attach those two original
  files and save. Keep existing assets, tag, version and description. Automation
  does not edit published releases/assets. The missing receipt also blocks
  authenticating this release as the next predecessor.
- [ ] Review/download **manual-release-assets** from
  [Release qualification 38223985994](https://github.com/klusik/PHP_gallery/actions/runs/38223985994)
  if CMS package/manual publication files below still need attaching.
- [ ] Keep `release/v_0.127.3` until the retirement fix reaches main. Its old
  reader still expects the removed historical reconciliation path.

## Integrate the repair feature

- [ ] Use **feature/fix-pages-release-deployment**. Its final remote head must be
  the one-commit squash directly over current remote develop.
- [ ] Wait for automatic **Basic source checks** and feature **Project website**
  verification on that exact SHA. A feature website build produces review
  artifacts; optional maintenance FULL is extra diagnostic evidence.
- [ ] Open **Actions -> Integrate into develop -> Run workflow**:

| Field | Value |
| --- | --- |
| Use workflow from | `main` |
| source_branch / Feature branch | `feature/fix-pages-release-deployment` |
| recover_existing | unchecked / `false` |

- [ ] Click **Run workflow** once. Wait for preparation, every required FULL job
  and **Publish develop integration**. Require exact-candidate qualification and
  the whole workflow to succeed. It automatically fast-forwards develop.
- [ ] Verify remote develop equals final candidate A. Generated preparation may
  append a generated-only commit: select final A, not an earlier source SHA.
  Concurrent develop changes require replanning, never a protected reset.

## Create the next release and approve its PR

- [ ] From updated qualified develop, after published v_0.127.3:

```powershell
git switch develop
git pull --ff-only origin develop
git switch -c release/v_0.127.4
git push origin HEAD:refs/heads/release/v_0.127.4
```

- [ ] The push automatically starts **Release qualification**. Do not manually
  dispatch completion or repeat the one-off integration bootstrap.
- [ ] Wait for immutable origin/predecessor validation, FULL evidence for A,
  notes, all four locked-toolchain manuals/PDFs, generated integrity state,
  BASIC changed-source/release-owned delta checks and publication assets.
  The ordinary release delta does not rerun A's functional matrix.
- [ ] Open the **bot-created release PR to main**. Review exact head Q, notes and
  all four PDF editions; complete reported manual coverage checks.
- [ ] Submit **Review changes -> Approve** once. Automation performs the protected
  standard merge, immutable tag, conditional develop FF and GitHub Release draft.
- [ ] Wait for **Release completion** to report merge/tag verified and develop
  reconciliation complete. Wait for other dependent completion runs too.

## Attach the original assets, then publish

- [ ] Download/extract **manual-release-assets** from the completed qualifier;
  attach its original files to the release draft:

| Files | Role |
| --- | --- |
| `production.zip` | CMS deployment distribution |
| `core-manifest.json`, `production-files.json`, `release-metadata.json` | Package/integrity/version metadata |
| `PHP_Gallery_Manual.pdf`, `PHP_Gallery_Manual_CZ.pdf`, `PHP_Gallery_Manual_DE.pdf`, `PHP_Gallery_Manual_SV.pdf` | Four manuals |
| `qualification-evidence.zip`, `SHA256SUMS`, `final-integrity.json` | Original qualification/package proof |
| `release-notes.md` | Original notes; retain the existing exact-Q draft description |

- [ ] Download/extract **release-completion-record** from the successful completion
  run. Attach **`release-evidence.json` and `release-evidence.sha256`** permanently.
  `release-completion.json` is additional diagnostic context.
- [ ] If produced, attach the matched installer and `winapp-update.json` from
  **manual-winapp-installer**. Generation is conditional; do not mix versions.
- [ ] Verify package checksums, PDFs and outstanding browser/manual checks.
  Click **Publish release** yourself.

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
