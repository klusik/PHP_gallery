# Patch Notes

Detailed release notes for PHP Gallery CMS. Versions are listed newest first.

## Version 0.38

### Major Changes

- Added a global theme background image upload stored in private cache storage
  instead of gallery media folders.
- Added a background opacity slider in the Theme admin panel, including a live
  percentage display while adjusting the value.
- Added theme-level header title color and gallery title color controls so dark
  backgrounds can keep text readable.
- Added a fallback background source at the theme level and per-gallery
  background source selection in gallery admin.
- Added public background rendering as a base color layer with an optional
  background image layer on top.
- Moved gallery breadcrumbs and action buttons into the hero panel so the
  public gallery header is more compact and aligned.
- Updated the header and hero surfaces to share the rounded-corner theme
  setting while keeping the hero styling translucent over the background.
- Hardened the Leaflet map overlay path so normal public maps and fullscreen
  split maps keep working after dynamic DOM changes and resize/rebuild cycles.
- Added a dedicated public route for serving the stored global theme background
  asset.

### Fixes

- Fixed normal gallery map overlays so they remain visible in public view.
- Fixed the inline-style guard so legitimate Leaflet runtime styles no longer
  trigger the tamper warning path.
- Fixed Leaflet tile sizing and viewport initialization during overlay
  creation.
- Fixed stale map pane errors caused by initializing the map before the
  overlay had finished sizing.
- Fixed the `background_source` gallery column schema so clearing the per-
  gallery setting can store `NULL` and fall back to the theme background.
- Fixed `custom.css` tracking so uploaded custom styles are ignored by Git.

## Version 0.37

### Major Changes

- Added a standalone admin reset entrypoint at `reset.php` so the site can be
  restored to the current stable branch head even when the normal admin update
  page is no longer usable after a broken beta deploy.
- Added a new gallery thumbnail asset path column so uploaded gallery cover
  images can be stored separately from imported gallery photos and served
  through a dedicated public route.
- Kept gallery-card cover rendering responsive by introducing thumbnail
  `srcset`/`sizes` hints and an intermediate 600px size, which lets the browser
  choose a sharper preview for wide cards without forcing the full 800px asset
  everywhere.
- Reduced gallery-page and lightbox overhead by batching per-image tag and vote
  lookups, memoizing request-scoped gallery helpers, and preloading adjacent
  lightbox images for smoother forward and backward viewing.
- Added reverse tag indexes so tag-filter pages and contained-tag lookups scale
  better on larger libraries.

## Version 0.36

### Major Changes

- Prepared the application metadata for the 0.36 release cycle.
- Removed browser-side and app-side reuse from the update check path so the
  admin update page always queries GitHub fresh.
- Added cache-busting request parameters and no-cache request headers to the
  GitHub version and archive fetches used by the updater.
- Kept the fullscreen split map improvements from the previous release:
  persistent map display during fullscreen browsing, restored map pins, and
  the desktop-only split layout for the map panel.
- Kept the non-image cache policy in place so HTML, CSS, JavaScript, JSON, and
  theme CSS do not linger from older gallery states.

## Version 0.35

### Major Changes

- Kept the fullscreen lightbox map split open when browsing to the next or
  previous image in fullscreen, so the map now persists until it is turned off
  or fullscreen mode ends.
- Restored the map pins in the fullscreen split view by reusing the same marker
  icon path as the normal public map overlay and keeping the Leaflet marker
  panes above the tile panes.
- Added a strict non-cache policy for non-image responses so browsers stop
  reusing stale HTML, CSS, JavaScript, JSON, and theme CSS from older gallery
  states.
- Versioned the main public stylesheet URL so updated UI and map code cannot be
  masked by a previously cached `styles.css`.

## Version 0.34

### Major Changes

- Prepared the application metadata for the 0.34 release cycle.

## Version 0.33

### Major Changes

- Prepared the application metadata for the 0.33 release cycle.
- Made `app/bootstrap.php` the single source of truth for update version checks.
- Reworded the beta update UI to say "beta code" instead of "Git commit hash".
- Switched stable rollback to restore the current GitHub branch head directly.

## Version 0.32

### Major Changes

- Prepared the application metadata for the 0.32 release cycle.
- Made `app/bootstrap.php` the single source of truth for update version checks.
- Reworded the beta update UI to say "beta code" instead of "Git commit hash".

## Version 0.31

### Major Changes

- Prepared the application metadata for the 0.31 release cycle.
- Hardened the GitHub update check so the admin update button can detect the
  newest version from both `PATCH_NOTES.md` and `app/bootstrap.php`.
- Made remote version parsing tolerate `v0.31` and `v_0.31` style headings, so
  release notes and tags cannot hide a valid newer version.
- Added explicit update-source reporting on the admin update page to show whether
  the detected GitHub version came from patch notes or the remote bootstrap file.

### Notes

- Upload this release, then push the same files to the GitHub branch used by the
  updater. Older installed copies will then see 0.31 as the newest available
  version even if one of the remote version sources is temporarily stale.

## Version 0.30

### Major Changes

- Made the lightbox fullscreen controls usable on mobile devices as a visible
  overlay action, while keeping desktop keyboard behavior unchanged.
- Simplified the mobile fullscreen path so it now relies on the app's own
  overlay state instead of depending on the browser fullscreen API.
- Added a lightweight debug flag for tracing the lightbox fullscreen toggle
  path during local testing.

## Version 0.29

### Major Changes

- Removed the public tamper-warning overlay so beta installs and normal gallery
  browsing no longer get blocked by the inline-style guard.
- Continued the manual beta updater work so the stable/beta distinction stays
  explicit while rollback remains available from the admin update screen.
- Kept the fullscreen and mobile viewer work aligned with the existing overlay
  model rather than introducing a separate viewer path.

## Version 0.28

### Major Changes

- Expanded the updater to support manual beta installs from a specific Git
  commit hash, with rollback to the last stable backup when beta is active.
- Continued the fullscreen and mobile gallery work with swipe-friendly viewer
  behavior and a CSS fallback path for devices that do not expose native
  fullscreen the same way.
- Kept the release and update UI aligned with the live version state so the
  admin navigation and update screen remain responsive to new releases.

## Version 0.27

### Major Changes

- Improved the public fullscreen overlay so the lightbox HUD, navigation, and
  centered image staging behave more consistently across normal and fullscreen
  states.
- Fixed update-badge detection so new releases are reflected immediately in the
  admin navigation rather than waiting on stale cached state.
- Continued the 0.26 admin polish by keeping migration prompts conditional and
  preserving the compact dashboard feature indicators.

## Version 0.26

### Major Changes

- Refined the public lightbox and fullscreen overlay so navigation and HUD
  controls behave more predictably in both normal and fullscreen states.
- Tightened the admin migration detection and dashboard presentation so pending
  migrations are shown only when needed and use the striped update treatment.
- Continued the gallery voting and picture game cleanup work with stricter
  admin-side state normalization and clearer per-gallery feature handling.

## Version 0.25

### Major Changes

- Added optional per-gallery voting controls with admin-side enable/disable
  support, public UI gating, and preserved vote history when disabled.
- Added admin dashboard bulk actions and compact status columns for gallery
  voting, GPS maps, and picture game settings.
- Added admin-side self-healing for gallery voting/game flag mismatches on
  dashboard load so game-enabled galleries always keep voting enabled.

## Version 0.24

### Major Changes

- Hardened public SEO routing without changing the working gallery navigation:
  - public gallery cards now link to clean `/gallery/{slug}/` URLs as the first
    baby step toward cleaner public navigation
  - other gallery navigation, redirects, forms, and admin links remain on the
    stable query-string route
  - clean `/gallery/{slug}/` URLs are supported and used as canonical URLs
  - nested `/gallery/folder/path/` URLs remain compatibility routes, but they
    are not used as generated public links
  - `/robots.txt` and `/sitemap.xml` now resolve correctly in subfolder installs
  - `base_url = ''` installs now emit root-relative app links instead of
    fragile page-relative links
- Improved crawler metadata:
  - gallery pages emit title, description, canonical, Open Graph, Twitter card,
    and JSON-LD metadata
  - gallery page headings keep a single `<h1>` that matches the resolved gallery
    title
  - image `alt` text falls back from caption metadata to filename to a
    gallery-based fallback
  - `sitemap.xml` lists public, non-protected galleries with absolute URLs
  - `gallery.json` tags can be comma-separated text or a JSON array
  - saved gallery sidecars now include gallery tags

## Version 0.23

### Major Changes

- Added filesystem-first gallery management groundwork:
  - changing a gallery parent now moves the real folder subtree on disk
  - changing the folder name on a gallery edit page renames the real folder
  - all moved descendant gallery `folder_path` values are updated together
  - failed folder moves are logged and leave an admin-visible error
- Added admin gallery creation and upload flows:
  - `Create empty gallery` creates a real empty folder and a gallery row
  - `Upload photos` can upload multiple images into an existing gallery
  - uploads can also create a new gallery folder before storing images
  - upload progress is shown in the browser, followed by existing thumbnail
    batch progress when optimized thumbnails are requested

## Version 0.22

### Major Changes

- The dashboard `Check for new gallery folders` button now also scans all
  already-imported galleries for new or changed direct image files before
  showing the discovery screen.
- Added an admin log entry and on-screen summary for that refresh scan, including
  how many existing galleries were scanned and how many image records changed.
- Added a visible wait indicator for the dashboard refresh scan so large gallery
  checks no longer leave the admin page looking frozen while the request runs.
- Added an admin gallery status filter for drafts, public galleries, and private
  galleries. The bulk `Select displayed galleries` checkbox now only selects
  rows that remain visible after filtering and collapsed tree branches.

## Version 0.21

### Major Changes

- Prepared the application for the next release cycle by bumping the active
  version number and keeping the updater badge logic aligned with the current
  installed release.

## Version 0.20

### Major Changes

- Fixed the pending-update indicator on the admin update page so the fresh
  GitHub check updates the same cached state used by the header and dashboard.
- Pending update links and buttons now show `Update(1)` and use the fixed
  warning background, including the primary update action button.
- The updater now checks both allowed GitHub branches and uses the highest
  advertised version, so a stale `main` branch cannot hide a newer `master`
  release.

## Version 0.19

### Major Changes

- The pending-update cache now invalidates when the application version changes,
  so the `Update(1)` badge stays accurate after a release.

## Version 0.18

### Major Changes

- Opened public gallery titles now use a slightly smaller display size while
  still taking the full panel width, so long names fit more comfortably without
  wrapping early on desktop layouts.

## Version 0.17

### Major Changes

- The admin Updates button now switches to a fixed warning style and shows
  `Update(1)` when a newer GitHub version is available.

### Notes

- The pending-update badge uses a cached GitHub check and does not use theme
  colors, so custom CSS and sliders cannot make it look like a normal button.

## Version 0.16

### Major Changes

- Theme controls now read defaults from the active CSS skin, and saved slider
  values override custom CSS through the generated theme stylesheet.
- Added a `Reset to CSS` button on the Theme screen to clear saved color, radius,
  and font overrides while keeping the selected custom CSS skin.
- Added a dedicated Theme color control for the open public gallery panel.

### Notes

- Existing installs with saved theme overrides may need one click on `Reset to
  CSS` to return the sliders to the selected CSS skin defaults.

## Version 0.15

### Major Changes

- Clarified the browser installation flow in the README so setup starts from the
  site root and redirects automatically to the installer when `config.php` is
  missing.

### Notes

- The installer still supports opening `install.php` directly, but that is not
  required for a normal first-time setup.

## Version 0.14

### Major Changes

- Added an admin-only application updater:
  - the Updates page checks GitHub `PATCH_NOTES.md` for a newer version
  - admins can install newer branch archives with one button when PHP `ZipArchive` and outbound HTTPS are available
  - overwritten application files are backed up under `cache/updates/backups`
  - local `config.php`, galleries, cache files, and active custom CSS are left untouched
- Share-link display tokens are encrypted at rest while link validation continues to use token hashes.

### Notes

- The updater does not delete obsolete files from older releases; remove those manually if a future release note asks for it.
- Keep a normal hosting backup before using one-button updates on production sites.

## Version 0.13

### Major Changes

- Added password-protected public galleries:
  - protected galleries can be listed publicly without thumbnails or set as unlisted/direct-link-only
  - protected access is inherited by subgalleries
  - visitors can unlock a protected branch with a gallery password for 10 minutes
  - admins can generate, regenerate, expire, or revoke share links
  - share-link-only galleries are an explicit admin access mode and generate a usable link when saved
  - generated share links use the canonical query route with the gallery id and token so the token cannot resolve to the wrong gallery
  - active share links remain visible in the admin edit form and can be revoked later, with the display token encrypted at rest
  - share links use `page=share&id=...&token=...` so they work without rewrite rules
- Added a follow-up migration for existing v0.13 installs so the persistent share-link token column is applied even when the first v0.13 migration already ran.
- Made configured `http://` base URLs upgrade to `https://` automatically on same-host HTTPS requests, including common reverse-proxy headers, so CSS and JavaScript are not blocked as mixed content after enabling HTTPS.
- Made same-host `base_url` paths self-correct when the configured path does not match the current front-controller path, which helps shared-hosting deployments where `/subdom/name` is an internal folder but the public site is served from the domain root.
- Added progress feedback to the gallery import flow when `Create optimized thumbnails during import` is checked.
- Prevented browser/mobile auto-translation wrappers from triggering the inline-style compromise warning, and marked the gallery document as non-translatable to preserve Czech gallery titles such as `Den 01`.
- Centralized protected-gallery access checks across public gallery pages, thumbnails, original media, downloads, maps, tags, votes, and the picture game.
- Added admin edit controls and dashboard access labels for protected/listed/unlisted galleries.
- Updated the installer and initial schema for the v0.13 protected-gallery fields.

### Notes

- Run database migrations after uploading this version.
- Regenerating a share link immediately invalidates the old link.
- Unlisted protected galleries and their subgalleries are reachable by direct link only.

## Version 0.12

### Major Changes

- Added optional EXIF/GPS map support for gallery branches:
  - image scans extract safe EXIF fields when the PHP EXIF extension is available
  - GPS coordinates are stored separately from the source file and refreshed on rescan
  - admins can enable EXIF GPS maps on a gallery branch, recursively including subgalleries
  - public image cards show a map pin only when the branch allows GPS maps and the photo has GPS coordinates
  - the lightbox shows a map button for GPS-enabled photos
  - gallery pages can open a combined map of all GPS-enabled public photos in the current gallery branch
- Added a migration for EXIF/GPS columns and the recursive gallery map flag.
- Added Leaflet/OpenStreetMap-based map overlays without requiring a paid Google Maps API key.
- Added a JSON gallery-map endpoint for the public gallery page and lightbox controls.

### Notes

- Run database migrations after uploading this version.
- Rescan galleries after migration so existing images receive EXIF/GPS metadata.
- OpenStreetMap public tiles are suitable for light usage and testing. For heavy public traffic, configure a dedicated tile provider later.

## Version 0.11

### Major Changes

- Simplified the browser installer migration runner:
  - `install.php` no longer wraps each migration file in an explicit database transaction
  - migration statements now run directly, which avoids redundant transaction handling during schema setup

### Documentation

- Updated `README.md` and `ARCHITECTURE.md` to describe the installer migration flow accurately

## Version 0.10

### Major Changes

- Added the optional picture comparison game for opt-in gallery branches:
  - pair history prevents the same viewer from seeing the same image pair again in the same gallery game
  - visitors can choose the left or right image with clicks or arrow keys
  - the chosen image receives a normal upvote
  - the game shows global top-picture statistics for the current gallery
  - admins can enable or disable the game across gallery trees
- Added a database-backed admin log:
  - admins can record operational events and failures without server log access
  - the log page supports workflow states, filtering, and bulk status updates
- Hardened migration-aware admin behavior:
  - admin features detect missing schema more safely
  - the dashboard shows a clear migration prompt when the schema is stale
  - admins can run migrations from the dashboard when needed
  - migration attempts and rejected admin actions are logged
- Kept the earlier public-page admin editing, lightbox vote display, custom CSS skins, and site-name configuration in the same release branch

### Documentation

- Updated the public UI documentation for admin log workflow, migration prompts, and picture-game admin flow

## Version 0.9

### Major Changes

- Added public-page admin editing:
  - logged-in admins can edit gallery names and descriptions directly from
    public gallery and subgallery cards
  - logged-in admins can edit photo titles and descriptions directly from public
    image cards
  - inline controls can publish, hide, or remove CMS records without deleting
    the underlying files from disk
  - admins can see draft/private images and subgalleries while browsing public
    gallery pages
- Improved the public lightbox metadata and voting experience:
  - image descriptions are visible in the overlay
  - missing descriptions show a clear `No description.` fallback
  - score is shown as a dedicated badge under the picture
  - up/down vote controls and the current vote indicator are visible under the
    picture metadata
  - keyboard up/down voting updates the overlay vote state
- Made the public site name configurable:
  - the default `Gallery CMS` label can be changed from Admin -> Theme
  - the configured name is used in the header and browser title
  - the home page no longer renders a default `Galleries` hero block when no
    gallery is selected
- Added selectable custom CSS skins:
  - the Theme screen lists `.css` files from `custom_css/`
  - selecting a skin copies it to `public/assets/custom.css`
  - uploaded CSS is still supported and can be reset
  - added a new `modern.css` skin with matching active CSS output
- Added an optional picture comparison game for gallery branches:
  - new galleries are opted out by default
  - admins can enable or disable the game from gallery edit pages
  - admins can bulk-enable or bulk-disable selected galleries and their
    subgalleries from the dashboard
  - picture-game controls stay hidden until the required migration is applied
  - stale databases show an admin-only `Run database migration` prompt instead
    of throwing a fatal error
  - public gallery pages show a `Play picture game` button when enough eligible
    public images exist
- Added side-by-side image voting:
  - two pictures are shown at the same visual height
  - visitors choose the picture they prefer by clicking it
  - left and right arrow keys can select the left or right picture
  - the selected picture receives a normal upvote
  - the non-selected picture receives no vote and is not downvoted
- Added per-viewer pair history:
  - image pairs are normalized so A/B and B/A are treated as the same pair
  - a pair is recorded as soon as it is displayed
  - the same viewer does not see the same pair again in that gallery game
  - when all pairs are depleted, the game shows a completion message
- Added global game statistics:
  - the game page shows the top three pictures for the current gallery game
  - stats are global, not per-user
  - top pictures show game wins and normal score

### Data Model

- Added `galleries.picture_game_enabled`
- Added `picture_game_votes` to store pair display history and selected winners
- Picture-game winners also write into the existing `image_votes` table so game
  choices contribute to normal image scores
- Added schema-readiness checks around picture-game admin controls so upgraded
  code can load before the new migration has been applied

### Documentation

- Added the root `PATCH_NOTES.md` file with backwards release history
- Updated README with the picture game workflow, admin opt-in behavior, voting
  rules, pair depletion behavior, statistics, selectable CSS skins, configurable
  site naming, and admin-run migrations
- Updated architecture notes with the new route, pair-history table, and admin
  workflow additions

## Version 0.8

### Major Changes

- Hardened the first-run installation flow:
  - unconfigured browser requests now redirect to `install.php`
  - `install.php` refuses to run after `config.php` exists
  - `install.php` also refuses to run after `cache/installed.lock` exists
  - successful browser installs write `cache/installed.lock`
  - the setup route self-locks when an administrator already exists
- Added admin account management:
  - logged-in admins can update their username
  - logged-in admins can change their password
  - current password verification is required before account changes
  - username uniqueness is validated
  - password confirmation and minimum length are enforced
  - sessions are regenerated after successful account updates
- Improved public thumbnail quality:
  - public gallery cards now use the `800` thumbnail variant
  - public image previews now use the `800` thumbnail variant
  - gallery cover collages now use the `800` thumbnail variant
  - the `300` thumbnail variant is kept for compact admin table previews
- Refined thumbnail administration:
  - dashboard "Create all thumbnails" now uses the AJAX batch workflow
  - thumbnail progress is shown consistently during long-running jobs
  - created and skipped thumbnail counts remain visible during processing
- Added documented custom CSS examples:
  - `custom_css/css_template.css` provides a commented starter template
  - `custom_css/custom.css` provides a compact admin-oriented example
  - the admin theme page can reset uploaded custom CSS

### Admin Workflow

- Added an `Account` navigation link for logged-in admins
- Added `page=admin_account` for authenticated account updates
- Made the admin gallery table denser for large gallery trees:
  - smaller table text
  - tighter cell padding
  - smaller checkboxes
  - smaller tree toggles
  - compact Edit and Thumbs row actions
- Reworked the dashboard "Create all thumbnails" control so it selects all
  galleries and runs the same thumbnail action used by the bulk form
- Kept gallery row and gallery edit thumbnail buttons available as normal form
  submissions when JavaScript is unavailable

### Security

- Prevented accidental installer reuse after setup by treating `config.php` as a
  hard installer lock
- Kept `cache/installed.lock` as a second installer lock signal
- Prevented the setup endpoint from replacing an existing admin account after an
  administrator has already been created
- Preserved the existing CSRF, session, and media visibility protections from
  previous releases

### Theme And Assets

- Added a custom CSS reset action to the Theme admin screen
- Continued loading uploaded custom CSS from `public/assets/custom.css`
- Added cache busting for `public/assets/gallery.js` using the file modified time
- Added `/galleries/` to `.gitignore` so local gallery media is not accidentally
  committed

### Documentation

- Updated `README.md` with the automatic installer lock behavior
- Updated deployment notes to explain that deleting or blocking `install.php` is
  now optional defense in depth
- Updated `ARCHITECTURE.md` to describe the first-run installer lock model
- Updated thumbnail documentation so public `800` thumbnails and admin `300`
  thumbnails are described accurately
- Documented the `custom_css/` example folder and its relationship to uploaded
  `public/assets/custom.css`

## Version 0.7

### Major Changes

- Added a generated thumbnail pipeline:
  - thumbnails are created inside each gallery folder under `thumbs/`
  - generated thumbnails are progressive JPEG files
  - supported sizes are `300` and `800`
  - stale thumbnails are rebuilt only when the source image is newer
  - up-to-date thumbnails are counted as skipped
- Added a protected thumbnail route:
  - `page=thumb&id=...&size=...` streams generated thumbnails
  - thumbnail access uses the same gallery and image visibility checks as media
  - missing thumbnails fall back through the normal media route where applicable
- Added AJAX thumbnail jobs:
  - thumbnail forms progressively enhance to batch requests
  - progress includes total images, processed images, created files, skipped files,
    and completion state
  - import, dashboard, gallery, and image workflows can create thumbnails
- Hardened public and admin requests:
  - CSRF protection was added to voting and admin actions
  - anonymous vote rate limiting was added per image
  - session cookies now use `HttpOnly`, `SameSite=Lax`, and `Secure` on HTTPS
  - global security headers were added
  - media MIME checks use `finfo`
  - media responses include stricter content headers
- Overhauled the admin gallery tree:
  - gallery rows can be collapsed and expanded
  - collapse state is saved in `app_settings`
  - `Collapse all` and `Expand all` controls were added
  - gallery and image select-all checkboxes were added

### Public Gallery Experience

- Public gallery cards now render thumbnail-backed images instead of originals
- Gallery image previews use generated thumbnails
- The lightbox was expanded with:
  - image counter
  - link to the original protected media route
  - keyboard help text
  - integrated voting controls
  - keyboard voting with up and down arrows
- Vote buttons were changed to compact icon-style arrows
- Inline-style tamper detection remains strict so visual changes go through theme
  settings or custom CSS

### Media Pipeline

- Media and thumbnails are served through controlled application routes
- Public media continues to respect gallery and image visibility
- Thumbnail and media responses include caching headers
- ZIP signatures and image scans were updated to work with direct gallery images
  and generated thumbnail folders
- Thumbnail folders are ignored by discovery and scans

### Admin Workflow

- Import can scan images and optionally create thumbnails in one flow
- Bulk gallery actions can create thumbnails
- Gallery edit pages can create thumbnails for all images or selected images
- Admin previews use thumbnails instead of full-size source images
- Gallery tree state persists across admin page reloads

### Documentation

- `README.md` gained detailed thumbnail workflow notes
- `README.md` now lists GD as required for thumbnail creation
- `ARCHITECTURE.md` documents thumbnail routing, AJAX batches, media visibility,
  and admin tree state
- Deployment scripts and notes were updated for the expanded media pipeline

## Version 0.6

### Major Changes

- Added public tag filtering through `page=tag&slug=...`
- Made gallery tags and image tags clickable on public pages
- Included image tags when filtering galleries by tag
- Added inherited `Containing tags` displays:
  - parent galleries aggregate tags from descendant galleries
  - parent galleries also aggregate tags from descendant images
  - top-level gallery cards can expose useful tags even when the top-level folder
    only contains subgalleries
- Added inline-style tamper detection:
  - public JavaScript checks for inline `style` attributes
  - a full-page warning is shown when inline styling is detected
  - theme customization is intentionally routed through theme settings or custom
    CSS

### Tag Workflow

- Tags became navigation controls on public pages
- Public tag pages list galleries connected to a tag through gallery tags or
  image tags
- Gallery cards were restructured so card links and tag links do not create
  invalid nested clickable markup
- Existing tag suggestions remain available in admin tag inputs

### Gallery Hierarchy And UI

- Replaced inline indentation styles in the admin gallery tree with fixed
  `tree-depth-*` classes
- Improved subgallery nesting display without relying on inline CSS
- Continued support for parent galleries and subgallery discovery from earlier
  releases

### Documentation And Code Clarity

- Expanded `README.md` with:
  - tag filtering behavior
  - inherited tag behavior
  - inline-style restrictions
  - naming conventions
  - UI conventions
  - CSS conventions
  - route conventions
  - form conventions
  - documentation expectations
- Expanded `ARCHITECTURE.md` with:
  - tag routes
  - inherited tag aggregation
  - inline-style warning behavior
  - route and request flow notes
- Added docblocks and explanatory comments across PHP, JavaScript, and CSS

## Version 0.5

### Major Changes

- Expanded project documentation substantially in `README.md`
- Documented the application as a plain PHP gallery CMS for shared hosting
- Clarified the supported environment:
  - PHP 8+
  - MySQL or MariaDB
  - PDO MySQL
  - ZipArchive
  - image metadata support through `getimagesize`
  - Apache `.htaccess` support as recommended but not mandatory

### Setup Documentation

- Added clearer manual setup steps:
  - copying `config.example.php`
  - editing database and path settings
  - creating the database
  - running migrations
  - creating the first admin user
- Documented the browser setup route for shared hosting without shell access
- Added local PHP built-in server instructions for root and `public/` web roots

### Usage Documentation

- Documented the gallery folder workflow
- Documented explicit gallery discovery from the admin area
- Documented admin scan and edit steps
- Documented query-string routes for environments without pretty URLs
- Documented ZIP download behavior and cache signatures
- Documented public voting behavior
- Documented FTP deployment and post-upload setup
- Added security notes for protected directories, media routing, escaping, SQL,
  CSRF, and password hashing

## Version 0.4

### Major Changes

- Added the broader plain-PHP application core:
  - routing
  - PDO database access
  - sessions
  - CSRF protection
  - migration runner
  - controller and service layers
- Added a standalone browser installer capable of:
  - creating the database
  - creating or updating the database user
  - writing `config.php`
  - creating writable folders
  - running migrations
  - creating the first admin account
- Added filesystem-backed gallery management:
  - gallery discovery from `galleries_root`
  - imports from filesystem folders
  - image scans
  - nested subgallery support
  - `gallery.json` sidecar metadata
  - ZIP download caching

### Admin Features

- Added an admin dashboard with gallery import and management actions
- Added bulk gallery and image actions
- Added editable gallery metadata:
  - title
  - description
  - slug
  - visibility
  - sort order
  - parent gallery
  - cover image
- Added editable image metadata:
  - title
  - description
  - visibility
  - sort order
- Added tags for galleries and images
- Added tag suggestions in admin forms
- Added theme settings:
  - accent colors
  - page and panel backgrounds
  - corner radius
  - font mode
  - custom CSS upload

### Public Features

- Added public gallery cards
- Added breadcrumb navigation
- Added subgallery display
- Added gallery cover images and cover collages
- Added lightbox browsing with keyboard navigation
- Added public image voting
- Added ZIP downloads for single public galleries
- Added admin download of all imported galleries

### Deployment And Documentation

- Added Apache rewrite and protection files for root, public, cache, and gallery
  directories
- Added FTP and local deployment scripts
- Added deployment exclusions for local-only files
- Expanded `README.md` with release highlights, setup, workflow, deployment, and
  security guidance
- Added `ARCHITECTURE.md` covering request flow, web-root layouts, migrations,
  filesystem rules, and protected directories

## Version 0.3

### Major Changes

- Added first-class nested subgallery behavior:
  - homepage lists only top-level public galleries
  - gallery pages can show direct child subgalleries
  - breadcrumbs show the path through parent galleries
  - parent relationships are synchronized from filesystem paths
- Added gallery title pictures:
  - galleries can choose an explicit cover image
  - galleries can automatically use the first direct image as a cover
  - parent galleries without direct images can show a collage from child gallery
    covers
- Changed scans to import direct images for each gallery instead of recursively
  importing every descendant image into the parent gallery

### Admin Workflow

- Added parent gallery selection on gallery edit pages
- Added title picture selection on gallery edit pages
- Added bulk gallery visibility actions
- Added bulk image visibility actions
- Added bulk action to set an image as the gallery title picture
- Added image previews to the admin gallery edit table
- Added per-gallery scan/import form on gallery edit pages
- Improved dashboard table with parent gallery and folder hierarchy information

### Filesystem And Import Behavior

- Discovery now detects folders that contain descendant images, allowing empty
  parent folders to become gallery records
- Parent galleries are imported before deeper child galleries
- Parent IDs are synchronized after imports
- ZIP creation now includes only direct images owned by each gallery
- Gallery ZIP and all-gallery ZIP signatures ignore descendant images that belong
  to child galleries
- `gallery.json` sidecars can persist cover image paths

### Public UI

- Gallery cards gained image covers and child-cover collages
- Gallery detail pages gained subgallery sections
- Breadcrumb navigation was added to gallery pages
- Public image grids show only direct images from the current gallery

## Version 0.2

### Major Changes

- Added the browser installer as a standalone setup path:
  - database creation
  - database user creation or update
  - `config.php` writing
  - migration execution
  - writable folder creation
  - first admin account creation
- Added database port support:
  - `config.example.php` includes a `database.port` value
  - the PDO DSN appends the configured port when present
- Improved MySQL compatibility:
  - installer supports server default authentication
  - installer supports `caching_sha2_password`
  - installer supports `mysql_native_password`
  - README explains how to handle missing `mysql_native_password`

### Deployment

- Added local deploy folder mode to the PowerShell deployment script
- Kept FTP upload mode available
- Added explicit deployment mode support through `deploy.bat`
- Improved deploy exclusions:
  - `.git`
  - `config.php`
  - cache folders
  - logs
  - temporary files
  - optional gallery media
- Preserved required `.htaccess` files for protected cache and gallery folders

### Documentation

- Added browser installer setup instructions
- Added local Laragon and shared-hosting oriented setup notes
- Added deployment mode examples:
  - `deploy.bat -Mode local`
  - `deploy.bat -Mode ftp`
- Clarified post-upload setup steps

## Version 0.1

### Initial Release

- Added the initial PHP Gallery CMS application structure:
  - root `index.php`
  - `public/index.php`
  - `app/` PHP application files
  - `database/migrations/`
  - `public/assets/`
  - `scripts/`
  - cache and gallery protection files
- Added core routing through `page=...` query-string routes
- Added Apache rewrite support through `.htaccess`
- Added database configuration through `config.example.php`
- Added migration support and initial schema
- Added CLI helpers:
  - `scripts/migrate.php`
  - `scripts/create_admin.php`
  - `scripts/deploy.ps1`

### Gallery Features

- Added filesystem-backed gallery discovery
- Added gallery import and image scanning
- Added gallery metadata storage in the database
- Added image metadata storage in the database
- Added public gallery listings
- Added public gallery detail pages
- Added protected media streaming through the application
- Added public image lightbox browsing
- Added public image voting
- Added ZIP download support with cache records

### Admin Features

- Added admin login and logout
- Added admin dashboard
- Added gallery edit pages
- Added image edit pages
- Added gallery visibility controls
- Added image visibility controls
- Added sort order fields
- Added manual setup route protected by `setup_key`
- Added CSRF helpers for admin forms

### Security And Deployment

- Added password hashing with `password_hash`
- Added prepared-statement based database access with PDO
- Added output escaping helpers
- Added path normalization and path-inside-root checks for gallery files
- Added protection guidance for `config.php`, application folders, cache folders,
  and gallery folders
- Added initial README with setup, workflow, routing, voting, ZIP, deployment, and
  security notes
