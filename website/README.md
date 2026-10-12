# PHP Gallery project website

The presentation site lives at **https://klusik.github.io/PHP_gallery/**.
It is separate from the PHP CMS. Authored content is in `content.json`, layout
in `build.mjs`, and reviewed public assets in `assets/`. Node 22+ renders static
HTML with no packages or browser JavaScript. EN is the root; CS, DE and SV have
their own routes. Each language has a `docs/` page. Language links preserve the
current page, and navigation works without JavaScript.

## Editing and preview

Update all four translations together. Keep product claims consistent with the
published CMS. Do not duplicate installation commands or technical requirements:
the documentation entry point links to the release README and four PDF manuals.
README and patch notes remain in their original language; changing the website
language changes the presentation, not those technical documents.

On a clean checkout with the release tags fetched:

```powershell
gh api repos/klusik/PHP_gallery/releases/latest > cache/website-release.json
$env:PHP_GALLERY_WEBSITE_RELEASE = "$PWD/cache/website-release.json"
$env:PHP_GALLERY_WEBSITE_OUTPUT = "$PWD/cache/website-preview/PHP_gallery"
node website/build.mjs
python -m http.server 8090 --directory cache/website-preview
```

Open `http://localhost:8090/PHP_gallery/`, including direct `/cs/docs/`, `/de/`
and `/sv/docs/` navigation. The output directory must not already exist: choose
a fresh directory for every build. Output under `cache/` is ignored and must
never be committed. No local application audit is part of ordinary website edits.

## Release ownership

The build reads the latest **published stable GitHub Release**, resolves its tag
to a commit and verifies that README, PATCH_NOTES and all four PDFs exist there.
Document links use that full release commit; download links use the canonical
release page. Development README version markers never determine the public
website version. `release.json` records the selected release, publication date
and immutable document commit. API/build failure blocks deployment and leaves the
last successful site available rather than publishing incorrect release data.

Publication events rebuild the site; a daily refresh also covers maintainer
publication through a workflow token that does not trigger another workflow.
The site therefore may lag a publication until the next successful daily run.
No fabricated live version or browser API fallback is used. Maintainers own
translations, screenshots and presentation claims; the published tag owns manuals,
README and patch notes. Releases and historical notes are never modified here.

## Assets and privacy

`gallery-preview.svg` is original synthetic landscape artwork, **an illustration,
not a screenshot**. `ofp-description-actions.webp` is the existing reviewed
interface crop from `docs/issue-assets/112/`: it contains Czech action labels and
help text, with no photographs, account identifiers, credentials or flight-plan
personal data. Keep the screenshot caption truthful. Review every replacement
asset for private galleries, tokens, account data and licensing before committing.

## Verification and publishing

`project-website.yml` uses the central `scripts/audit.php --suite=website`
entry point for website artifact, route and owned Chromium checks. It does not
rerun application/database/WinApp regressions. Feature pushes build and verify an
artifact without deployment. BASIC source qualification remains the installed
feature gate; FULL belongs to the maintainer's develop-integration action.
Hosted evidence includes desktop/mobile overview and documentation screenshots
for visual review. Deployment refuses an artifact whose main source was superseded.

Only `cache/website-output/` is uploaded as the Pages artifact, with eight static
documents, reviewed assets, `.nojekyll` and `release.json`. There is no PHP,
runtime data, application repository copy or CMS deployment path. The production
inventory already excludes the `website/` root. Workflow source and fixtures
stay outside the Pages artifact.

After normal reviewed integration and release promotion install this workflow on
`main`, the maintainer must change **Settings → Pages → Build and deployment →
Source → GitHub Actions** once. The current legacy root-of-main source must not
remain active. This feature branch does not change the server setting, publish
the site, mutate protected branches or dispatch release promotion. Main pushes,
published releases, daily refresh and manual main dispatch can then deploy through
the `github-pages` environment. A dispatch from a feature branch only verifies.
Do not enable bypass or change branch protections. The public project URL stays
unchanged. Inspect the website build summary, audit evidence and Pages deployment
URL, then review desktop/mobile presentation at that URL after first activation.
