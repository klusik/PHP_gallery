# Patch Notes

Detailed release notes for PHP Gallery CMS. Versions are listed newest first.

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
