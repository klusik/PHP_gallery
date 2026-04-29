# Patch Notes

Detailed release notes for PHP Gallery CMS. Versions are listed newest first.

## Version 0.13

### Major Changes

- Added password-protected public galleries:
  - protected galleries can be listed publicly without thumbnails or set as unlisted/direct-link-only
  - protected access is inherited by subgalleries
  - visitors can unlock a protected branch with a gallery password for 10 minutes
  - admins can generate, regenerate, expire, or revoke share links
  - share-link-only galleries are an explicit admin access mode and generate a usable link when saved
  - generated share links use the canonical query route with the gallery id and token so the token cannot resolve to the wrong gallery
  - active share links remain visible in the admin edit form and can be revoked later
- Added a follow-up migration for existing v0.13 installs so the persistent share-link token column is applied even when the first v0.13 migration already ran.
- Made configured `http://` base URLs upgrade to `https://` automatically on same-host HTTPS requests, including common reverse-proxy headers, so CSS and JavaScript are not blocked as mixed content after enabling HTTPS.
- Made same-host `base_url` paths self-correct when the configured path does not match the current front-controller path, which helps shared-hosting deployments where `/subdom/name` is an internal folder but the public site is served from the domain root.
  - share links use `/share/{token}` and only store token hashes in the database
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
