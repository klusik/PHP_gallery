# PHP Gallery project website

The presentation site lives at **https://phpgallery.site/**.
It is separate from the PHP CMS. Authored content is in `content.json`, layout
in `build.mjs`, and reviewed public assets in `assets/`. Node 22+ renders static
HTML without packages. EN is the root; CS, DE and SV have their own routes.
Each language has a `docs/` page. Language links preserve the current page.
Canonical, EN/CS/DE/SV alternate, English `x-default` and Open Graph URLs share
the production origin declared once in `build.mjs`. English starts at `/`;
translated routes start at `/cs/`, `/de/` and `/sv/`. Relative navigation and
assets also work under the test-only `/PHP_gallery/` preview mount below;
that mount never appears in production metadata.
The compact translucent presentation shares light/dark palette tokens across
the overview and documentation. `assets/site.js` optionally switches the
page-local palette and enlarges authentic application screenshots in a native
dialog. It makes no network requests and stores no browser preferences.
Static navigation, documentation, radio screenshot tabs and full-image links
work without JavaScript. Browsers without native dialog support retain those
ordinary image links permanently; the website enhancement owns that fallback.

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
never be committed. No local application audit is part of ordinary website
edits.

## Release ownership

The build reads the latest **published stable GitHub Release**, resolves its tag
to a commit and verifies that README, PATCH_NOTES and all four PDFs exist there.
Document links use that full release commit; download links use the canonical
release page. Development README version markers never determine the public
website version. `release.json` records the selected release, publication date
and immutable document commit. API/build failure blocks deployment and leaves
the
last successful site available rather than publishing incorrect release data.

Publication events rebuild the site; a daily refresh also covers maintainer
publication through a workflow token that does not trigger another workflow.
The site therefore may lag a publication until the next successful daily run.
No fabricated live version or browser API fallback is used. Maintainers own
translations, screenshots and presentation claims; the published tag owns
manuals,
README and patch notes. Releases and historical notes are never modified here.

## Assets and privacy

The four `assets/real-*.jpg` files are original browser viewport captures,
authorized by the repository owner for this presentation and captured on
2026-10-25 in Gallery 0.143.1. The captured version is independent of the
dynamically selected published release. Keep that distinction in every locale.

- `real-gallery.jpg` (1353 × 874): public Flight Simulators overview, with
  actual A320/A321/A330 cards, tags, search and photo/PDF/music counts.
  Source: <https://galerie.klusik.cz/gallery/game-screens/flight-simulators/>.
- `real-lightbox.jpg` (1280 × 720): photograph 21/77 in the public Westbound
  Tour, LEG 36 gallery, including the real zoom/fullscreen/presentation/map HUD.
- `real-map.jpg` (1368 × 884): that gallery's Japan–China route and photograph
  locations. Leaflet/OpenStreetMap attribution remains visible.
  Source for both flight views:
  <https://galerie.klusik.cz/gallery/game-screens/flight-simulators/a320/westbound-tour-leg-36-from-kitakyushu-airport-to-shanghai-pudong-international-airport-rjfr-zspd/>.
- `real-music-admin.jpg` (1265 × 712): the authorized local Music Manager,
  showing the existing Flying album and its three tracks. The capture contains
  no credentials, account identifiers or private contact information. Its public
  source link is the corresponding documentation at captured commit
  `b9bf3f211059cfd70523fbf4a2cde0d02f2f17bd`; no private Admin URL is published.

Keep screenshot bytes unchanged: fit complete images with `object-fit: contain`,
without cropping controls, retouching, filters or simulated product UI.
Website language and palette changes preserve captured pixels and their
original Czech/English interface labels. Enlargements explain that controls
inside an image belong to the capture and provide the public source link.
Image content retains its existing owners' rights; the MIT code license does
not grant new rights to depicted photographs or simulator assets. Review every
replacement for private data, tokens, licensing and truthful captions.

## Verification and publishing

`project-website.yml` uses the central `scripts/audit.php --suite=website`
entry point for website artifact, route and owned Chromium checks. It does not
rerun application/database/WinApp regressions. Feature pushes build and verify
an
artifact without deployment. BASIC source qualification remains the installed
feature gate; FULL belongs to the maintainer's develop-integration action.
Hosted evidence includes desktop/mobile overview and documentation screenshots
plus a desktop dark-palette capture. The existing suite checks both palettes'
composed text contrast, unchanged capture bytes, native no-script selection,
source links, modal arrows/Escape and focus restoration. Deployment refuses an artifact whose main source was
superseded.

Only `cache/website-output/` is uploaded as the Pages artifact, with eight
static
documents, `site.css`, optional `site.js`, four reviewed JPEG captures,
`.nojekyll` and `release.json`. There is no PHP,
runtime data, application repository copy or CMS deployment path. The production
inventory already excludes the `website/` root. Workflow source and fixtures
stay outside the Pages artifact.

After normal reviewed integration and release promotion install this workflow on
`main`, the maintainer must change **Settings → Pages → Build and deployment →
Source → GitHub Actions** once. The current legacy root-of-main source must not
remain active. This feature branch does not change the server setting, publish
the site, mutate protected branches or dispatch release promotion. Main pushes,
published releases, daily refresh and manual main dispatch can then deploy
through
the `github-pages` environment. A publication event retains the tag ref even
when
checkout selects main: run 38225431172 was rejected by the main-only
environment.
Stable publication now validates the latest published release and Actions-based
Pages configuration, then dispatches a fresh **Project website** run on `main`.
Only that new main-context build may deploy. The release-context handoff has
Actions write and Pages read permission, with no Pages write or OIDC token.
Feature dispatches only verify, and the main dispatch cannot recurse into
another
publication handoff. Keep the environment restricted to the `main` branch; tags
do not need deployment access.
Screenshot evidence is captured from the same fully loaded DevTools session that
asserts each route. The suite retains seven full-document desktop/mobile PNGs and
checks their viewport width and format; it does not start a second CLI browser
whose navigation can stall before producing an image.
Do not enable bypass or change branch protections. Keep the configured custom
domain in place. Inspect the website build summary, audit evidence and Pages
deployment
URL, then review desktop/mobile presentation at that URL after first activation.
