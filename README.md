# PHP Gallery CMS

A modern PHP 8.1+ gallery CMS designed for ordinary shared hosting. The application uses the filesystem as the authoritative source for gallery structure, while storing all metadata, access rules, votes, user accounts, and audit logs in MySQL or MariaDB.

**Current Version:** 0.96.1

**Key Benefit:** Deploy in minutes on shared hosting. No npm, no Composer, no framework overhead. Just PHP + MySQL.

## Core Features

### Gallery Management
- **Filesystem-first design** - Galleries are folders on disk; the database mirrors and enhances them
- **Nested galleries** - Create gallery hierarchies with unlimited depth
- **Gallery discovery** - Automatically detect and import new folders
- **Manual creation** - Create empty gallery folders from the admin interface
- **Bulk operations** - Rename, delete, move, reorder, or change visibility for multiple galleries at once
- **Gallery metadata** - Title, description, optional date range, cover image, custom slug, and safe external links with local brand icons or cached site favicons
- **Folder management** - Moving galleries physically relocates the folder tree on disk

### Image Management
- **Upload interface** - Upload multiple images to a gallery via browser
- **Automatic scanning** - Detect newly added files on the filesystem
- **Image editing** - Edit title, caption, tags, visibility, sort order per image
- **Bulk image operations** - Reorder, tag, or delete multiple images
- **EXIF display** - Show image metadata (camera, lens, ISO, GPS coordinates) on public pages
- **EXIF/GPS defaults** - GPS maps and coordinates are enabled globally by default, with per-gallery inherit, force on and force off controls plus a dashboard reset for all overrides
- **EXIF date suggestions** - Suggest gallery date ranges from original photo capture dates, including subgalleries, with one reusable editor component for full admin pages and side panels plus editable admin approval
- **Duplicate photo detector** - Compare exact SHA-256 and metadata-supported possible duplicates as left/right pairs, open gallery/photo context directly, remember reviewed pairs or exact galleries in a per-admin ledger, and delete confirmed copies in place
- **GPS maps** - Render interactive maps when images have location data and the effective EXIF/GPS policy allows display

### Thumbnails & Performance
- **Automatic generation** - Create optimized thumbnails during import or on-demand
- **Multiple formats** - JPEG and WebP variants for browser compatibility
- **Responsive sizing** - Automatically serve appropriately-sized images for device
- **Two public thumbnail renderers** - Progressive small-first sharpening is the default; selected-gallery photo cards can optionally use the legacy responsive browser-selection pipeline from Admin > Theme > Layout
- **Quality tuning** - Configurable JPEG quality with automatic size optimization
- **DNG RAW support** - Generate display masters from DNG raw files
- **Lazy loading** - Deferred thumbnail loading in fullscreen gallery
- **Maintenance tools** - Regenerate, delete, or verify thumbnail cache
- **Admin log scalability** - Browse grouped operational events, export large histories in bounded batches, and keep recent records in the live database while archiving older days safely

#### Public thumbnail rendering modes

The site-level `public_thumbnail_rendering_mode` setting controls photo cards inside a selected gallery and accepts only `responsive` or `progressive`. Missing or invalid values resolve to `progressive`. Gallery cover cards, subgallery collage cells, home-page gallery cards, and Admin thumbnails continue to use responsive browser selection.

**Responsive browser selection - Legacy** renders normal server-side `<picture>/<img>` markup with the complete available WebP/JPEG candidate set immediately. The browser can select among generated 300, 600, 800, and 960 px candidates, subject to actual derivatives and thumbnail bounds. It works fully without JavaScript.

**Progressive thumbnail sharpening - Default** is the current Admin-facing label for the primary pipeline. PHP still renders a real small thumbnail, normally the 300 px derivative, plus semantic links and alt text. Larger candidates remain inert until the optional browser module sees a visible or near-visible card, measures it, and preloads/decodes the smallest adequate replacement. JavaScript-disabled visitors keep the functional small thumbnail.

Progressive rendering prioritizes perceived initial responsiveness, not minimum total transfer. A visitor can download both the initial small thumbnail and a later larger replacement, so total transferred bytes can be higher than responsive mode even when the page feels ready sooner. Default and Legacy are Admin-facing status labels only; neither changes the permanent `progressive` and `responsive` machine values.

### Access Control
- **Visibility modes** - Public, unpublished (admin-only), or private (password/token protected)
- **Password protection** - Per-gallery passwords with session-scoped unlock
- **Share links** - Generate time-limited or permanent share tokens
- **Inheritance** - Child galleries inherit parent access rules
- **Admin-only zones** - Some galleries only visible when logged in

### Viewer Accounts (Invite-only Registration)
- **Administrator account management** - Admin can create and delete viewer accounts directly, or create/list/revoke invitation links without pre-creating an account
- **Administrator security controls** - Admin can suspend or restore a viewer account and force sign-out on every viewer device; suspension and restoration rotate viewer security authority without deleting favourites or private collections
- **Forced first-login password replacement** - Directly created accounts use a generated or Admin-supplied temporary password; normal viewer authority and Remember me stay blocked until the user replaces it
- **Password-safe notification** - Optional account-created mail contains the trusted login URL but never the temporary password, which is shown once to the administrator for separate delivery
- **Administrator-issued invitations** - Invitation recipients still follow the existing email-verification activation flow and choose their own initial password
- **Verified activation** - Invitation recipients verify their email before a durable viewer account is activated and choose a password of at least 15 characters
- **Separate viewer login** - Viewer identity is independent from administrator identity and never grants protected-gallery access by itself
- **Remember me** - Dedicated rotating viewer persistent credential, separate from Admin persistent login and recent reauthentication
- **Password recovery** - Generic, scanner-safe viewer reset flow using the configured bounded mail transport
- **Private account and favourites** - Signed-in email, viewer-only logout, a private favourites page, and small favourite controls on authorized gallery cards/lightbox images
- **Private viewer collections** - Viewers can create, rename, delete, order, and browse private collections of authorized image references; source authorization is re-evaluated whenever a collection is rendered
- **Unlisted read-only collection sharing** - A viewer may issue one revocable 30-day share link for an owned collection; recipients need no account, the secret is exchanged into a narrow session grant and removed from the displayed URL, and every rendered source image still passes the recipient's current gallery/media authorization without Admin bypass
- **Viewer account lifecycle** - Authenticated viewers can change password, stage and verify a new email address, and permanently delete their own viewer account through the existing Phase 0.7 lifecycle services
- **Recent reauthentication** - Password change, email-change initiation, and account deletion require recent viewer password proof; remember-me restoration alone is intentionally insufficient
- **Master feature wrapper** - The complete viewer-account subsystem is registered in **Admin > Features** and is disabled by default. While off, Viewer accounts is hidden from Admin navigation, public viewer Login/Account UI is absent, and viewer routes fail closed without allowing existing accounts to authenticate.
- **Invite-only mode inside the feature** - After the master feature is enabled, **Admin > Account > Viewer accounts** exposes the existing subordinate viewer-frontend toggle; enabling it selects the supported `invite_only` mode and no `config.php` edit is required.
- **Authorization remains independent** - Saving a favourite or collection item stores only the canonical image reference and never preserves or grants access to a protected source gallery
- **Still deliberately absent** - Open signup, public viewer profiles or collection discovery, recipient ACLs/collaboration, uploads, comments, and optional viewer MFA/social-login mechanisms

### Tagging & Organization
- **Reusable tags** - Global tag system shared across all galleries and images
- **Tag metadata** - Display name, slug, description, usage statistics
- **Gallery tagging** - Apply multiple tags to galleries for organization
- **Image tagging** - Tag individual images for detailed categorization
- **Public tag pages** - Generate filterable listing pages by tag
- **Tag suggestions** - Auto-complete tag input when editing
- **Tag management** - Admin interface to create, rename, edit, or delete tags

### Voting & Scoring
- **Image voting** - Visitors can rate images (+1/-1 or like/dislike)
- **Gallery voting** - Rate entire galleries
- **Score display** - Show aggregated scores on public pages
- **Vote persistence** - Track per-user votes (anonymous via IP hash, logged-in via session)
- **Togglable voting** - Enable/disable voting per gallery

### Gallery Navigation
- **Top navigation shortcuts** - Optionally show up to three admin-selected favorite galleries or the main gallery page as direct header buttons
- **Breadcrumbs** - Navigate hierarchy on public pages
- **Gallery cards** - Display subgalleries with cover images and metadata
- **Pagination** - Handle large galleries without overwhelming the browser
- **Fullscreen viewer** - Lightbox with keyboard navigation, cursor-centered 100-400% zoom/pan, EXIF overlay
- **Picture game** - Side-by-side image comparison game (optional per-gallery)

### Lightbox Image Zoom

Open any public photograph and use the visible `−`, percentage/reset, and `+` controls to inspect details from 100% to
400%. The same controls remain available in fullscreen. Keyboard users can press `+`/`=`, `-`/`_`, and `0`. Mouse-wheel
and trackpad zoom keeps the photograph point under the pointer stable; touch pinch keeps the gesture midpoint stable.
When no valid pointer anchor is available, discrete zoom uses the photograph center. An enlarged photograph can be dragged
in both axes. Ctrl/Command-modified wheel gestures are left to the browser, so normal page zoom remains available.

The 100% photograph is fitted and centered inside the stage. Zoom does not enlarge pixels inside a fixed 100% frame.
Instead, the real `.lightbox-zoom-surface` grows symmetrically around the fitted photograph center and is translated only
for pan. Cursor anchoring is calculated from the canonical fitted dimensions, current scale, and current translation,
rather than from a CSS transition that may still be between frames. Repeated wheel or keyboard zoom therefore does not
accumulate drift toward a corner. In fullscreen, the stage clips the enlarged photograph to the viewport while preserving
horizontal and vertical pan range; the close and other HUD controls remain above the zoomed media layer and clickable.

Zoom is temporary browser presentation state. Moving to another photograph, starting slideshow, closing, or reopening
the viewer returns to a centered 100% view. A pure fullscreen toggle preserves the current scale and reclamps translation
to the new viewport.

The viewer starts with the generated preview when it contains enough pixels for the current 100% stage. A passive
quality calculation may promote a very large or high-DPI 100% view. The moment the visitor deliberately zooms above
100%, however, the active image is switched directly to its existing protected full/original display URL in that same
input action. It does not wait for fullscreen, resize, a density threshold, an animation frame, or a detached decode pass.
The currently visible preview can remain on screen only while the original response transfers. The zoom frame and pan
state stay unchanged when the sharper resource becomes visible, so changing between lightbox and fullscreen is never
required to refresh image quality. A translated loading pill and activity indicator remain visible while the larger source
is being prepared.

Only the active photograph is upgraded. The viewer never constructs a raw-file URL and does not eagerly download full
sources for neighboring photos. A failed full-source request restores the authorized preview and leaves zoom/pan usable.
Very large originals can still use substantial bandwidth and decoded memory, and sharpness cannot exceed the detail in
the stored source or DNG display master.

The feature does not store a preference or alter files or metadata. Preview and full requests continue through the same
authorized thumbnail/media routes, so gallery, share, NSFW, map, voting, pagination, Smart Gallery, and no-JavaScript
behavior remains unchanged.

### Downloading
- **ZIP archives** - Download a single gallery, Smart Gallery, or all accessible galleries
- **Progressive browser downloads** - Modern browsers POST for a short-lived resource-scoped capability, receive a bounded private manifest, and stream independently authorized originals into a local ZIP with progress, cancellation, retry, duplicate-name handling, and ZIP64 support
- **Revision-keyed manifest reuse** - Repeated progressive requests reuse private capability-free metadata for unchanged content; visitor authorization and capability validation still run on every protected request, and Smart Gallery result membership is recomputed before reuse
- **Explicit no-JavaScript fallback** - Legacy server ZIP preparation is POST-only, separately capability-scoped, file/byte bounded, single-flight/global-admission controlled, and reuses immutable managed artifacts; GET/HEAD compatibility requests never build ZIPs
- **Unthrottled authorized source transfer** - Original files remain private and are streamed through the authorized PHP source endpoint; the project does not require hosting-specific internal-redirect modules
- **Signed downloads** - Optional signature verification for secure links

Deployment-tunable download limits are centralized in `app/configuration_defaults.php` and may be selectively overridden through `runtime_limits` in local `config.php`. Stage 7 manifest-cache defaults are 24 hours for physical galleries, 15 minutes for Smart Galleries, 16 MiB maximum metadata-entry size, and 10,000 entries per bounded maintenance scan. Existing installations require no configuration edit after update.

### Theming & Customization
- **Theme editor** - Customize colors, fonts, spacing and default lightbox browsing mode from the admin interface
- **Lightbox browsing modes** - Use the classic single image viewer, a picture strip, or a focused 3D carousel as a Theme default with per-gallery overrides
- **Dark mode** - Switch between light and dark themes
- **Language support** - English, Czech, German, and Swedish; English fallback retained
- **Gallery branding** - Per-gallery logo, background, cover image
- **Site branding** - Site-wide logo and background
- **Custom CSS** - Direct CSS editing for advanced customization
- **Layout control** - Choose gallery card layout (vertical/horizontal) and favorite gallery/main-page shortcuts

### Updates & Maintenance
- **One-click updates** - Check GitHub and install newer versions from admin dashboard
- **Update channels** - Follow stable or beta release branches
- **Patch notes viewer** - Cached release notes with version browsing
- **Backup on update** - Changed/removed application files preserved in each durable job under `cache/updates/jobs/<job-id>/rollback/`
- **Emergency recovery** - Revert to stable branch from `reset.php`
- **Database migrations** - Automatic schema evolution with admin-triggerable execution
- **Database inspection** - Explicit full-schema inventory with migration/code audit, cleanup reasons, and protected-table policies
- **Safe logical cleanup** - Dry-run and bounded resumable removal of proven orphans, deterministic duplicates, and explicitly expired temporary state, with exact row identities audited transactionally
- **Physical database maintenance** - Selected `ANALYZE TABLE`, selected-table optimization previews, and separately confirmed selected `OPTIMIZE TABLE`, never automatic
- **Admin log archive maintenance** - Configure live-log retention, create protected day archives, inspect archive state, and recover safely after interrupted archive work

### Admin Tools

#### Dashboard
- Overview of galleries, images, and system status
- Quick-access links to common tasks
- Rendering performance profiler (development mode)
- Database integrity checker
- Thumbnail cache analyzer

#### Discovery & Import
- Scan `galleries/` folder for new directories
- Preview detected galleries with folder paths and titles
- Bulk import with optional thumbnail generation
- Create completely new galleries from scratch

#### Upload Interface
- Multi-file upload to existing or new gallery
- Progress bar for transfer and thumbnail generation
- Immediate scanning after upload
- Validation of file types and sizes
- Optional browser-side preparation with client thumbnails, EXIF metadata, bounded ZIP batches, and server-side validation; the configured ZIP size is a normal packing target, while one oversized atomic image package may use a singleton batch up to the detected PHP upload limit; user-selected ZIP archives are unpacked locally and contribute only supported images
- Explicit browser-side processing for selected photos: when checked, client preparation failures stop before persistence instead of silently switching thumbnail generation to PHP; uncheck the option to choose the standard server-side upload path

#### Gallery Management
- Gallery list with filtering and search
- Expandable/collapsible hierarchy tree
- Bulk rename, delete, move galleries
- Reorder galleries within parent hierarchy
- Inline gallery editing with side panel
- Cover image selection and upload
- Manual gallery date ranges with From and To fields
- EXIF-derived date range suggestions that can be applied directly from a gallery editor without a full page reload, or reviewed, edited and ignored per gallery branch
- Duplicate photo detector in the gallery Admin side panel with selected-branch/all-gallery scope, linked gallery/photo context, per-admin reviewed-pair/exact-gallery ledger controls, and AJAX **Delete this** cleanup

#### Image Management
- Per-gallery image grid
- Bulk image tagging and visibility changes
- Inline image editing with side panel
- Drag-and-drop image reordering
- Image caption and metadata editing
- EXIF preview and GPS display

#### Tag Management
- Create, edit, rename, or delete tags
- Add descriptions and display names
- View usage statistics per tag
- Bulk tag operations
- Tag auto-complete in edit forms

#### Admin Audit Log
- Comprehensive audit trail of all admin actions
- Filter by action type, gallery, image, date range
- HTTP method and AJAX flag diagnostics
- Request fingerprinting for security analysis
- Export logs as JSON, CSV, or ZIP
- Status tracking (To Do, In Progress, Done, Waiting)

#### System Health
- Database integrity verification
- File-to-database consistency checking
- Automatic thumbnail rebuilding when needed
- Database migration runner
- Scheduled maintenance for bounded thumbnail checks, metadata refresh, and cleanup work
- File permission diagnostics

#### Account Settings
- Username and password management
- Optional recovery email for password reset
- Email delivery settings (PHP mail() or SMTP)
- SMTP configuration with TLS/SSL options
- Password reset link lifetime configuration
- Test email functionality

#### Telemetry & Statistics
- Anonymous usage statistics (opt-in)
- Gallery and image counts
- Feature usage analytics
- Privacy-respecting collection (no personal data)
- Export and review collected data
- Disable telemetry completely if desired

## Installation

### Fast Shared-Hosting Installation (Recommended)

The easiest way to install on shared hosting is the one-file bootstrap installer:

1. **Create database** - Create an empty MySQL or MariaDB database in your hosting control panel
2. **Upload one file** - Upload `setup-gallery.php` via FTP to your web directory
3. **Open in browser** - Visit `https://example.com/setup-gallery.php`
4. **Run installer** - Click "Download and start installer"
5. **Configure** - Enter database credentials, choose admin username/password
6. **Done** - The installer sets up everything automatically

The bootstrap installer:
- Downloads the full application from GitHub directly on your server
- Checks if the gallery is already installed (prevents accidental overwrites)
- Creates the database schema via migrations
- Creates the first admin account
- Generates `config.php` with your settings
- Creates `galleries/` and `cache/` folders

After successful installation, you can delete `setup-gallery.php` via FTP as an extra hardening step.

### Requirements

**Minimum:**
- PHP 8.1 or newer
- MySQL 5.7+ or MariaDB 10.2+
- PDO MySQL extension
- ZipArchive extension
- GD extension (for thumbnail generation)

**Recommended:**
- Apache with `.htaccess` support (for pretty URLs)
- Outbound HTTPS access (for updates and GitHub release notes)

**Shared hosting considerations:**
- Query-string routes work without Apache rewrites
- If FTP upload limits file size, split the upload (e.g., `public/assets/` separately)
- Contact host support if outbound downloads are blocked

### Full Package Installation

If you prefer to upload the complete package manually:

1. Upload all files to your web server via FTP or your host's file manager
2. Open the site in your browser - it automatically redirects to the installer
3. Choose your setup mode:
   - **Shared hosting:** Use existing database (create via control panel first)
   - **Local/VPS:** Let installer create database and user
4. Enter database credentials and first admin username/password
5. The installer creates everything and locks itself with `cache/installed.lock`

### Manual Shell Setup

If you prefer command-line setup or your host doesn't allow browser installation:

```bash
# 1. Copy example config
cp config.example.php config.php

# 2. Edit config.php with your database credentials, paths, etc.
nano config.php

# 3. Create database (via MySQL client or phpMyAdmin)
mysql -u root -p < schema.sql

# 4. Run migrations
php scripts/migrate.php

# 5. Create first admin account
php scripts/create_admin.php myusername mypassword
```

Then visit `https://example.com/index.php?page=setup&key=YOUR_SETUP_KEY` to verify schema is current.

### Local Development

Run locally with PHP's built-in server:

```bash
# From repository root, serve galleries/ as public
php -S localhost:8000 index.php

# Or serve public/ as root
php -S localhost:8000 -t public public/index.php
```

Then open `http://localhost:8000/` in your browser.

## Admin Workflow

### Centralized Settings

Use **Settings** in the Admin navigation as the central overview for important global configuration. The hub is intentionally not one giant form. It groups stable peer sections for General, Public appearance, Content, Media and browsing, Uploads and automation, Privacy and diagnostics, and Advanced configuration. Each section shows current values and whether a value is explicitly configured, inherited, or using its default.

The hub can directly edit only settings that already have a safe canonical service setter: site name, public language, URL rewrite, public search when available, the public thumbnail renderer, the global EXIF/GPS display default when its existing schema is ready, and development diagnostics. Theme layout, tag presentation, upload tuning, telemetry, Account credentials, language-pack editing, raw CSS, API keys, database tools and destructive maintenance remain on their existing specialized pages. Those pages remain fully supported and link back to the relevant Settings section.

Version 0.96 expands gallery-to-gallery migration into a resumable tree-transfer workflow. Administrators can include a complete descendant hierarchy, import it as a new child tree beneath the selected receiving gallery, and move originals, existing thumbnails, branding assets, metadata, translations, and flight-map data in bounded ZIP packages. Interrupted transfers ask the target which package assets are already present before retrying, while exact-version, API-key scope, schema-readiness, checksum, path, and completion checks remain enforced. No database migration or configuration change is required.

Deep links use stable identifiers such as `?page=admin_settings&section=appearance#settings-appearance`. JavaScript tab changes update the complete query plus hash URL so Back/Forward and refresh preserve the selected section. Without JavaScript, the tab links load the same section as normal pages. See `docs/ADMIN_SETTINGS_INVENTORY.md` for canonical ownership, defaults, fallbacks, sensitivity and migration status.

### Initial Setup (First Time)

1. Log in at `?page=admin_login` with the admin account you created during installation
2. Go to **Discovery** to scan for new gallery folders on disk
3. Review detected folders and import selected ones
4. Leave "Create optimized thumbnails" checked for better performance
5. Watch progress bar for import and thumbnail generation

### Regular Gallery Operations

#### Creating a Gallery

**Option A: From FTP** (for advanced users)
1. Create a folder under `galleries/` with image files
2. Use admin **Discovery** to import the new folder

**Option B: From Admin Interface**
1. Click **Create empty gallery** on the dashboard
2. Enter gallery name and choose parent gallery
3. (Optional) Upload images immediately
4. Publish when ready

#### Adding Images

**Option A: Upload from Admin**
1. Select a gallery and click **Upload photos**
2. Drag-and-drop files or click to browse
3. With JavaScript enabled, see transfer and thumbnail progress
4. After upload, images are scanned and thumbnails created

**Option B: Via FTP**
1. Upload image files to the gallery folder via FTP
2. Use admin **Scan for new images** to import them
3. Create thumbnails from the admin interface

#### Finding Duplicate Photos

1. Open an existing gallery in the Admin editor.
2. In the Images section, choose **Find duplicate photos**. The detector opens in the existing right-side Admin panel.
3. Leave **Search all galleries** unchecked to inspect the selected gallery and all nested subgalleries. Enable it explicitly to inspect all galleries available to the administrator.
4. Start the scan. JavaScript processes stored image metadata in bounded AJAX batches; normal POST forms remain available only as the non-JavaScript/direct-page fallback.
5. Review deterministic left/right duplicate pairs. Exact pairs use matching SHA-256 content; possible pairs use normalized EXIF evidence. File size is corroborating information only.
6. Click the gallery title/path to open that public gallery, or the preview/filename/gallery-relative path to open the public photo context. These links open a new tab so the Admin detector panel remains available.
7. Choose **Ignore this pair from now on** after reviewing one relationship. The canonical image pair is saved to your administrator ledger and is omitted from later searches without suppressing unrelated pair combinations.
8. Choose **Ignore all from this gallery** independently on the left or right card to suppress future pairs involving that exact gallery. Gallery rules do not imply parent or child galleries, so nested galleries can be reviewed independently.
9. Use **Clear ledger** to remove your stored pair and gallery decisions and make those findings eligible again.
10. To remove one confirmed duplicate, choose **Delete this**. With JavaScript enabled, delete and all ledger controls run immediately through AJAX, keep the right-side panel open, do not navigate or reload the page, and refresh only detector state. The delete path reuses the normal gallery image deletion service. POST/redirect behavior is fallback-only.
11. If older image rows are missing checksums or EXIF metadata, run the existing **Scan/import images** workflow first. The detector reuses stored scanner output and does not re-read every file during result requests.
12. Apply pending database migrations before using the persistent review ledger. Migration `202608080001_duplicate_photo_ledger.php` creates the two per-administrator ledger tables.


#### Organizing Galleries

1. Use **Gallery list** to view the hierarchy
2. **Bulk actions** to rename, move, or change visibility of multiple galleries at once
3. **Drag-and-drop reordering** to change display order (JavaScript enabled)
4. **Edit gallery** to change metadata, date range, cover image, tags, description layout, or lightbox browsing-mode override
5. Use the EXIF date suggestion beside the gallery date range to apply a range computed from that gallery and all subgalleries; the same component is used in the full editor and side-panel editor, JavaScript updates the fields in place, and the normal POST fallback still works
6. Use **Review branch suggestions** or **Gallery dates** in Admin maintenance to approve, edit or ignore EXIF-derived ranges for a parent trip gallery and its subgalleries

#### Editing Images

1. Select a gallery to see its images
2. Click **image thumbnail** to open the side panel editor
3. Edit title, caption, tags, visibility, or sort order
4. Or **bulk edit** multiple images at once

#### Managing Tags

Public tag pages can use a dedicated presentation. In Theme > Appearance > Gallery tags, configure galleries per row, rows per page, and the gallery-card design. The Edit tags screen provides a Configure tag display shortcut to these settings.

1. Go to **Tags** in the admin menu
2. Create new tags with display names, slugs, and descriptions
3. View usage statistics for each tag
4. Rename, edit, or delete tags
5. Tags auto-complete when editing galleries or images

### Ongoing Maintenance

#### Checking Gallery Health

1. Go to **Integrity** to verify database consistency
2. The checker looks for:
   - Orphaned gallery records (no folder on disk)
   - Missing images in database but visible on disk
   - Thumbnail cache health
   - File permission issues

#### Managing Admin Log

1. Go to **View log** to see all admin actions
2. Filter by type, gallery, date, or search text
3. Mark items as To Do, In Progress, Done, or Waiting
4. Export logs as JSON/CSV/ZIP for auditing
5. Old logs are kept (retention configurable)

#### Updating the Application

Public entry points load a dependency-free early runtime guard before the normal bootstrap. During the updater's file-activation critical section, new requests receive a private, non-cacheable `503 Service Unavailable` response until activation is durably complete; authenticated update recovery/status requests remain available. The guard also converts uncaught and catchable fatal PHP failures to bounded `500` responses where PHP can still control the response. Production hosting must keep `display_errors` disabled so the PHP runtime does not print warning or fatal details before the safe response handler runs.

1. Go to **Updates** to check GitHub for new versions and read patch notes.
2. Click **Update**. The Admin panel starts a durable job under `cache/updates/jobs/` and advances it through short authenticated requests instead of holding one PHP request open for the whole release.
3. The job checkpoints `download`, archive validation, bounded extraction, manifest verification, activation planning, file staging, rollback backup, readiness, activation, migrations, finalization, cleanup, and completion. Archive validation itself checkpoints every 500 entries; individual archive files are capped at 32 MiB and total expanded size at 512 MiB so one extraction unit cannot become an unbounded application payload.
4. Downloaded archives, extracted files, and the ready tree stay outside the active installation. Every installable release file must be covered by `app/core-manifest.json`; unsafe ZIP paths, symbolic links, oversized archives, missing files, stale manifests, and hash mismatches are rejected before activation.
5. Preflight hashes the incoming release against the active tree, so byte-identical files are excluded from activation. The complete pre-update snapshot of files that can change or be removed is made durable before activation. `config.php`, gallery media, cache data, custom CSS, hosting INI files, and other protected paths are never replaced by the updater.
6. If PHP, the browser, FastCGI, or a proxy stops a normal stage, reopen **Updates** and continue. Completed checkpoints are not repeated unnecessarily. The same live job card is visible on both **Status** and **Advanced tools**, so beta/reinstall progress remains visible where the operation starts. Worker access is serialized with per-job `flock()` plus a global active-job start lock; direct requests for an old job cannot run beside the active update, and active-pointer release is compare-and-clear safe. Stale lock-file text cannot keep a job blocked after the operating-system lock is released. A failed or running job can also be **Cancel prepared update** before activation starts; this clears its active-job slot without touching application files. Deterministic release-manifest mismatches are not offered as retryable because downloading the same invalid build again cannot repair it; cancel that job and select a newer release or beta code.
7. Activation is the only intentionally non-yielding critical section. It contains prepared local replacements for changed files only. Each replacement uses a sibling temporary file plus `rename()`, and replay recognizes files already matching the prepared hash. A host kill can still expose a brief mixed-version tree because ordinary shared hosting has no portable atomic directory swap; the next worker invocation completes the same activation stage.
8. Migrations run one migration file per updater checkpoint. Individual file copy/hash operations and one migration file remain inherently non-interruptible units; archive entries are capped at 32 MiB and rollback refuses an individual managed active file above 128 MiB rather than starting an unbounded snapshot copy. A completed migration is recorded in `schema_migrations`. If PHP dies inside one migration file, that file may replay, so migration definitions and repair callbacks must remain safely rerunnable.
9. After activation begins, the Admin job card can roll application files back from the saved snapshot. File rollback does not reverse database migrations. Stable restore and clean reinstall are separate resumable download/install operations.
10. With JavaScript enabled the Admin side panel stays open and refreshes progress in place. Without JavaScript, **Continue update** and **Retry from checkpoint** submit ordinary authenticated POST requests and preserve the direct-request fallback.

Automatic stable updates use the same state machine. The standalone first-install `setup-gallery.php` bootstrap predates the application and therefore cannot use the authenticated updater engine; it remains a separate one-request bootstrap path and manual ZIP upload remains the fallback on hosts that terminate that initial download/extract request. When enabled, request-time discovery is throttled to at most once per hour per installation, while GitHub rate-limit responses and backoff windows are still respected. Remote version discovery has its own approximately eight-second wall-clock budget shared fairly across configured GitHub branches, and a discovery request only creates the durable background job; package work begins on a later invocation. Normal safe page requests can then advance a short background slice. A caught background failure waits at least 60 seconds and then goes through the same stage-aware retry cleanup before another worker attempt. On an idle site, schedule `php scripts/application_update.php` from hosting cron, for example every five minutes; each invocation either performs bounded discovery or advances the existing background job, not both. The CLI runner does not advance manual/Admin beta jobs. Correctness never depends on `set_time_limit()` or `ignore_user_abort()`.

#### Customizing Appearance

Theme > Appearance > Gallery tags controls the public tag-page grid and card design independently from the normal gallery defaults.

1. Go to **Theme** to customize colors, fonts, layout, the default lightbox browsing mode, and selected-gallery public thumbnail rendering
2. Choose light or dark mode
3. Set default gallery card layout (vertical or horizontal)
4. Choose language (English, Czech, German, or Swedish)
5. Upload site-wide logo and background
6. Edit raw CSS if you need advanced styling

#### Running Database Migrations

When you see a "migration pending" notice on the dashboard:

1. Click **Run database migration** (appears automatically)
2. Or visit **Admin > Migrations**
3. The runner validates all pending migration definitions before making changes
4. SQL statements and optional PHP repair callbacks execute in filename order
5. A migration is recorded only after its statements and repair callback succeed
6. No action is needed after completion - new features become available

## Configuration

The application reads configuration from `config.php` (generated during installation). You typically don't need to edit this manually, but key settings include:

```php
$config = [
    'database_host' => 'localhost',
    'database_name' => 'gallery',
    'database_user' => 'gallery_user',
    'database_password' => 'secure_password',
    'galleries_root' => dirname(__DIR__) . '/galleries',
    'zip_cache_path' => dirname(__DIR__) . '/cache/zip',
    'base_url' => '', // relative URLs when empty
    'admin_session_name' => 'GALLERY_ADMIN_SESSION',
    'visitor_vote_secret' => 'random_string_for_vote_hashing',
    'setup_key' => '', // set during install, clear after
];
```

## Architecture Overview

For developers interested in the codebase structure, see **[ARCHITECTURE.md](ARCHITECTURE.md)** for:

- Request routing and controller dispatch
- Data model and relationships
- Service layer organization
- Feature implementation details
- Performance optimizations
- Security practices
- Database migration system
- Updater staging and recovery behavior
- Browser-prepared uploads and scheduled maintenance

## Performance Tuning

### Thumbnail Generation

By default, thumbnails are generated automatically during import. You can fine-tune this:

1. **Batch generation** - Go to **Thumbnails** to regenerate missing thumbnails in bulk
2. **Quality settings** - Edit in theme settings to balance quality vs. file size
3. **WebP support** - Modern browsers download smaller files automatically
4. **Lazy loading** - Fullscreen galleries defer thumbnail loading

### Large Galleries

For galleries with 1000+ images:

1. Enable **pagination** in theme settings (defaults to 50 per page)
2. Use **filtering/search** to narrow results
3. Create **sub-galleries** to organize logically
4. Add **tags** to improve findability

### Shared Hosting

On shared hosting with limited resources:

1. **Disable telemetry** if not needed (admin > telemetry settings)
2. **Delete old logs** (admin > logs, export, then prune)
3. **Clean thumbnail cache** periodically (admin > thumbnails > delete thumbnails)
4. **Use image compression** before uploading (optional but recommended)

## Security

### Passwords

- Admin passwords are hashed with bcrypt (PHP's `password_hash()`)
- Gallery passwords are hashed and checked per-visit
- Password reset links are token-based with expiration

### Sessions

- Admin sessions use secure cookies:
  - `HttpOnly` - JavaScript cannot access
  - `SameSite=Lax` - CSRF protection
  - HTTPS-only when SSL is detected
  - Timeout: browser session (closes when browser exits)

### File Uploads

- File type validation (MIME type checking)
- Extension whitelist (only images allowed)
- Uploaded files never executable (`.htaccess` prevents PHP execution)
- Filenames sanitized to prevent path traversal

### Database

- All queries use parameterized statements (no SQL injection)
- Prepared statements with bound variables throughout
- Connection-level permissions (can't drop tables, etc.)

### Output Encoding

- All user-controlled content HTML-escaped
- Attribute escaping for URLs and form fields
- JSON encoding for API responses

### CSRF Protection

- All state-changing requests require CSRF token
- Token regenerated per-session
- Validated on every POST/PUT/DELETE

### NSFW Guard schema safety

NSFW Guard verifies both gallery-level and image-level protection columns before
making public access decisions. A complete schema uses the normal inherited
gallery and per-image restrictions. A confirmed older schema follows the
documented pre-feature compatibility path and Admin System Health recommends
the migration. If the database cannot provide a trustworthy schema answer,
NSFW-sensitive gallery, media, thumbnail, lightbox, map, and metadata requests
receive a temporary 503 response. Explicit NSFW setting changes are paused
until verification succeeds.

Schema readiness results are cached only for one request. Migration execution
clears that cache after schema changes and repair callbacks, so an Admin,
installer, updater, or CLI migration process can immediately validate the new
schema instead of reusing a pre-migration answer. Repeated NSFW checks within a
normal request reuse the same two column inspections.

### Security and authentication schema safety

The same three-state inspection model now covers gallery password/access policy,
gallery visibility compatibility, share-token storage, persistent administrator
login, password reset, and Google identity links. The operational distinction is
important:

- **available** means the required database objects were verified and the normal
  feature behavior is used;
- **missing** means metadata inspection succeeded and confirmed an older or
  incomplete schema. Only explicitly documented compatibility behavior is used;
- **unknown** means the application could not verify the schema. Security-sensitive
  behavior is temporarily refused rather than silently downgraded.

Gallery password/access compatibility is deliberately conservative. The old
unprotected/listed behavior is used only when all five core access columns are
confirmed absent. If even one access column exists while another is missing, the
database is considered partially migrated and protected public routes fail closed.
This prevents an interrupted migration from turning stored password, listing, or
token state into permissive defaults.

Gallery visibility also verifies the database vocabulary. A confirmed historical
`visibility` definition that lacks `unpublished` stores that value as legacy
`draft`. If the column definition cannot be inspected, the application does not
guess which vocabulary is active.

Protected gallery pages, media, thumbnails, lazy lightbox/map metadata, search,
sitemap output, gallery assets, and gallery downloads pass through the shared
public schema-policy boundary before their controller can emit protected output.
An unknown access/privacy capability receives the same route-appropriate 503
behavior used by the NSFW pilot, with bounded request correlation and no SQL,
credentials, tokens, DSNs, or filesystem paths in the public response.

Authentication keeps the primary PHP session separate from optional persistent
storage. A confirmed missing `admin_remember_tokens` table disables only “Keep me
signed in”; ordinary password/session login remains available. Password reset
requires both `users.email` and `password_reset_tokens`. Google login requires a
verified `user_google_accounts` table in addition to valid OAuth configuration.
Unknown metadata state never masquerades as “feature not configured” or “invalid
credentials”.

Share-token generation/use requires verified token storage. Revocation is a safe
exception: once the core validating hash columns are verified, the application can
always clear the hash even when the optional encrypted display-token column is
missing or uninspectable. This means a schema problem cannot prevent an existing
share link from being revoked.

Admin **System Health** and **Runtime Diagnostics** now show the same bounded state
for gallery access, visibility, share tokens, NSFW Guard, persistent login,
password reset, and external identity links. Missing/unknown states produce an
Action signal and operator guidance without exposing raw database errors.

### Destructive and ingestion schema safety

Phase 10 extends the same distinction to operations that change persistent data or
files. Gallery/image deletion and moves, Duplicate Photo Detector ledger writes,
classic/browser uploads, upload automation, gallery migration, mobile WebDAV,
thumbnail metadata maintenance, database cleanup/repair, and application update
activation now use `app/services/mutation_schema_policy.php`.

The practical rule is:

- **available**: the workflow continues with its established behavior;
- **missing**: the workflow either requires the pending migration or uses a narrow
  compatibility/bootstrap path that was specifically audited for that operation;
- **unknown**: the workflow is paused before it changes target files, database rows,
  credentials, resumable migration state, derivative files, or active application
  files.

This matters most during temporary database or metadata-permission problems. A
legacy boolean “exists” helper can return `false` both when a column is genuinely
absent and when inspection itself failed. That ambiguity is acceptable only in
older non-sensitive compatibility code. It is no longer used to authorize the
Phase 10 mutation paths.

Uploads preflight the core gallery/image registration schema and thumbnail write
shape before a source file is moved from temporary/prepared storage into the
gallery. Gallery migration performs equivalent checks before target assets,
originals, metadata, thumbnails, or completion state are committed, so a refusal
leaves the job resumable. Thumbnail generation and maintenance verify the metadata
write shape before derivative directories/files are changed. A confirmed old
installation without the thumbnail metadata table can still use the documented
file-only compatibility mode; an **unknown** metadata state cannot.

Credential revocation is intentionally narrower than credential creation or use.
Upload automation and mobile WebDAV require their complete verified token schema
for issuance/authentication, but an administrator may still revoke/delete an
existing credential when the smaller set of columns needed for revocation is
verified. This keeps a schema problem from unnecessarily preventing a
security-tightening action.

Database repair and application updates also distinguish confirmed absence from
inspection failure. A confirmed missing `schema_migrations` table may be handled by
the migration/bootstrap workflow itself. An unknown state blocks live cleanup or
active-file replacement. Update archives may still be downloaded and extracted to
staging, but activation checks the schema immediately before replacing active
application files. Source snapshots must include both `schema_inspection.php` and
`mutation_schema_policy.php`.

Admin **System Health** and **Runtime Diagnostics** show ten additional mutation
capabilities covering these workflows. Missing and unknown states raise the
Maintenance **Action** badge. Unknown entries can include validated affected
table/column names, safe connectivity/permission guidance, and a request reference.
They do not expose raw SQL, database exception messages, DSNs, passwords, API keys,
WebDAV secrets, upload paths, migration source paths, or updater staging paths.

If an administrator sees a mutation-schema warning, first verify database
connectivity, the selected database, and metadata-inspection permissions. Apply a
pending migration only when System Health confirms a **missing** state or the normal
migration page reports it. After the database becomes inspectable again, retry the
operation. A refused Phase 10 workflow is designed to leave its target state
recoverable rather than guessing through an indeterminate schema.

### Optional presentation and reporting schema safety

The final schema-reliability phase covers features that enrich the gallery but are
not required to render its basic public content: GPS/EXIF maps, flight maps, image
voting, Picture Game, lightbox mode overrides, OpenAI assistance, local AI image
metadata, SimBrief route-map persistence, navigation datasets/accounts, telemetry,
and the Complete Admin Gallery Report. These features now use
`app/services/presentation_schema_policy.php`.

The important difference from access control and destructive operations is that a
safe optional **read** can sometimes disappear without taking the whole gallery
offline. For example, if a GPS-map table/column cannot be inspected, the main gallery
may still render without the map because omitting a map does not make protected
content public. The unavailable map is logged with bounded context and Admin System
Health shows the capability as **unknown**. By contrast, a vote, Picture Game pair
record, telemetry setting, AI queue transition, navigation-account token update, or
other database-backed write is never authorized by an unknown schema result.

Administrator-visible states are now consistent across all three schema-policy
layers:

- **available**: the required database objects were verified;
- **missing**: inspection succeeded and confirmed that a migration/optional object is
  absent;
- **unknown**: the database structure could not be verified reliably;
- **disabled**: an optional feature with a real feature flag is intentionally off.

System Health evaluates optional feature flags before inspecting their database
shape. A disabled feature therefore reports **disabled** without spending metadata
queries on tables/columns that cannot affect the current request. Enabled capability
checks share the request-local schema cache.

Several compatibility details are deliberate:

- a proven old installation may omit an optional presentation feature, but a partial
  migration or inspection outage is not silently relabeled as legacy;
- GPS per-gallery inheritance verifies that `galleries.gps_map_enabled` is nullable
  before treating `NULL` as a real inherit/default state;
- lightbox override compatibility verifies the stored column vocabulary before
  accepting override values. Gallery creation, sidecar import, and metadata-organizer
  child creation also distinguish confirmed legacy absence from an inspection outage,
  so an explicit/inherited override is not silently discarded during persistence;
- enabling voting on a newly created or sidecar-imported gallery requires verified
  voting storage rather than persisting a feature flag against an indeterminate vote
  schema;
- Picture Game pair selection is treated as a write because displaying a new pair
  records that pair before a vote occurs;
- Navigraph/OAuth can use historical session-only behavior only when persistent
  account storage is positively confirmed missing. An inspection outage cannot be
  treated as a session-only legacy installation;
- disconnect/revocation uses the smaller verified account identity shape needed to
  remove stored credentials, so a security-tightening disconnect is not blocked by
  unrelated optional account columns;
- SimBrief description/OFP generation can remain useful even when optional flight
  route persistence is unavailable. The draft may continue while only the database
  route-map write is skipped;
- telemetry dashboards/exports distinguish “migration not installed” from “database
  status cannot be inspected.” Telemetry setting changes and maintenance do not
  silently succeed on unknown schema;
- AI worker requests return bounded operational errors when AI queue storage cannot
  be verified and do not echo raw database/service exception text back to the worker;
- the Complete Admin Gallery Report uses structured checks for known optional
  sections. Its database table inventory intentionally enumerates
  `information_schema.TABLES` dynamically because it must count every base table,
  including future/plugin tables whose names are not known in advance. Inventory
  failures produce generic report text rather than raw database errors.

Admin **System Health** and **Runtime Diagnostics** expose fifteen optional
presentation/reporting capability entries using the same underlying policy results.
An unknown entry may show validated affected table/column names, safe database
connectivity/permission guidance, and a request reference. It never includes raw SQL,
PDO exception text, DSNs, credentials, OAuth/API tokens, cookies, reset/share tokens,
CSRF values, or private filesystem paths.

For troubleshooting, treat **missing** and **unknown** differently. Apply migrations
when the migration runner or System Health confirms required objects are missing. If
the state is **unknown**, first restore database connectivity, select the correct
database, and ensure the application account may read metadata from
`information_schema`; then retry. Do not apply migrations merely because an optional
feature disappeared during an inspection outage.

This completes the planned repository-wide schema-inspection reliability conversion.
The permanent architecture, database, testing, operator guidance, and administrator
manual now own the final rules; there is no release-time temporary phase roadmap.

### Admin Audit

- Every admin action logged with:
  - Timestamp and action type
  - Subject (gallery, image, etc.)
  - User account
  - HTTP method and IP fingerprint
- Logs exportable for compliance


## Localization and Language Packs

PHP Gallery keeps English (`en`) as the canonical source language and the runtime fallback. English, Czech (`cs`), German (`de`), and Swedish (`sv`) are the currently maintained and selectable interface languages. All four catalogs are kept key-for-key complete.

Gallery and photo titles/descriptions may be tagged with their source language and optionally translated into any maintained language. Existing text remains unchanged when unclassified. The viewer-language choice selects matching content where available. Gallery title and description fields fall back independently; a saved photo-language variant is treated as one caption and does not mix blank fields with source-language text. Other-language fields remain behind the language control in gallery and photo editors. Configured OpenAI text assistance can insert a reviewable translation draft; nothing is published until the normal editor form is saved.

`app/services/translations.php` restricts language selection to the maintained set above. Additional JSON skeletons may remain under `app/lang/` for future translation work, but simply placing a file there does not make that language selectable. English remains the runtime fallback if a maintained translation ever lacks a key.

Admin and public language choices are independent:

- **Admin interface language** is stored for the administrator in the session and browser cookie.
- **Public visitor language** is a site-wide default stored in application settings.
- **Viewer language selector** is enabled by default and can be disabled from Theme > Language or Settings > General. It applies only to public viewers: each viewer's personal selection is persisted in that viewer's browser cookie, not in an account or as a site-wide language change. When disabled, public pages follow the site-wide public language and ignore personal language query/cookie overrides.
- **Viewer languages** controls which maintained languages are offered for that browser-local viewer preference. All four are enabled by default, and at least one must remain selected when saving. This availability list is site configuration; choosing one of its languages affects only the viewer who makes the choice in that browser.
- Every public page provides the enabled language control in the shared header. Locally bundled SVG flags provide a visual cue; native language names remain the accessible labels.
- Selecting a maintained language keeps the current page and its filters, then remembers the per-viewer override in a public-language cookie. The same behavior remains available through `?lang=<code>` links.
- **Use site default** clears both the visitor session override and public-language cookie so later site-wide default changes apply again.
- Changing the interface language does not translate gallery titles, descriptions, tags, photo captions, or other owner-authored content.

Editable catalogs live in `app/lang/<code>.json`. JSON is the maintained format. The `en.php`, `cs.php`, `de.php`, and `sv.php` dictionaries remain compatibility fallbacks and are loaded only when a JSON pack for that code does not exist. Theme > Language exposes only the currently supported four languages in the Admin and Public selectors and pack editor, reports key coverage against English, and provides JSON edit/import/export tools plus missing-key diagnostics.

The reusable viewer-language settings panel is rendered by `app/views/admin_language_settings.php` in both Theme > Language and Settings > General. Both surfaces persist through the same translation service and `app_settings` keys: `public_language_selector_enabled` (`1` by default) and `public_language_selector_languages` (an ordered JSON list defaulting to all maintained languages). Disabling a language affects only visitor overrides; it does not remove that catalog from Admin language selection, the public site-default selector, editing, import/export, or diagnostics.

The same panel also owns the public selector design. Settings > General intentionally exposes only the basic preset and flag controls and links to Theme > Language for detailed editing without overwriting saved custom values. Five presets are available: Classic (the backward-compatible default), Solid pills, Outline, Soft cards, and Minimal. The compact Theme editor configures codes, native names, orientation, density, alignment, active emphasis, theme/custom colors (each color may instead be transparent), padding, margins, gaps, borders, radii, flag dimensions, and text size while an actual-flag preview appears above the fine controls and updates without saving. Reset all returns the complete design to Classic defaults, Reset this preset restores only the active preset, and every individual control has its own reset. All reset operations remain unsaved until the parent form is submitted and never alter the selector feature switch, enabled viewer languages, public default, or a viewer's browser-local language choice.

Current selectable language packs:

| Code | Display name | Current role |
| --- | --- | --- |
| `en` | English | Complete canonical source, default, and fallback |
| `cs` | Čeština | Complete maintained selectable translation |
| `de` | Deutsch | Complete maintained selectable translation |
| `sv` | Svenska | Complete maintained selectable translation |

The selector flag artwork lives in `public/assets/flags/`: United Kingdom for English, Czechia for Czech, Germany for German, and Sweden for Swedish. The SVGs are pinned from `lipis/flag-icons` v7.2.3 and distributed under its MIT license, included as `public/assets/flags/LICENSE.flag-icons.md`. They are served locally and never fetched from a third party at runtime.

When adding interface text, add the canonical English key to `app/lang/en.json` first and keep Czech, German, and Swedish synchronized. Placeholder names such as `{count}`, `{gallery}`, or `{time}` are part of the translation contract and must remain unchanged across all four maintained languages. Dormant future-language skeletons may be translated separately without becoming selectable.

## Extending & Contributing

### Adding Features

The codebase is organized for easy extension:

1. **New controller** - Add file in `app/controllers/`
2. **New service** - Add file in `app/services/` for business logic
3. **New route** - Register in `app/bootstrap.php` route table
4. **New migration** - Add dated file in `database/migrations/`
5. **Translations** - Add the canonical key to `app/lang/en.json` and keep `app/lang/cs.json`, `app/lang/de.json`, and `app/lang/sv.json` synchronized

### Code Standards

- Strict types enabled (`declare(strict_types=1)`)
- Type hints on all function parameters and returns
- HTML escaping with `e()` helper
- SQL parameterization with `?` placeholders
- No external dependencies (pure PHP)

### Testing

Run the complete standalone regression suite:

```bash
php scripts/audit.php --profile=full
```

The tracked `tests/` tree is the authoritative framework-free suite, and `php scripts/audit.php` is its canonical orchestration entrypoint. Use `--profile=quick` during edit cycles, `--profile=full` for complete deterministic source verification, and `--profile=release` before publishing. The runner executes PHP, explicitly registered Node, WinApp Python, syntax, contract, and release-specific checks without streaming successful child output into the agent context. It writes a compact `cache/test-audit/latest.md`, a machine-readable `latest.json`, and per-suite drill-down logs. Direct focused PHP/Node commands are for diagnosis, not the default full-suite workflow. `php tests/run.php` remains a PHP-suite compatibility wrapper. Production deployment packages exclude tests by default. For a local source-review ZIP, use `./deploy.sh --mode local --deploy-folder deploy --upload-media false --make-zip-deploy true --include-tests true` (PowerShell: `scripts/deploy.ps1 -Mode local -DeployFolder deploy -UploadMedia false -MakeZipDeploy true -IncludeTests true`). The opt-in is refused for FTP deployment.

### Release preparation

`RELEASE.md` is the authoritative release playbook. Start by comparing the worktree with the exact previous release tag, then run `php scripts/prepare_release.php <version>` to update only registered mechanical version markers and create a patch-note scaffold when needed. Complete release notes/documentation, rebuild and inspect the manual, then run `php scripts/generate_manifest.php` followed by exactly one `php scripts/audit.php --profile=release`. Release profiles are not a quick/full/release staircase. The release audit includes `scripts/check_release.php`, manifest freshness, browser integration when available, and Git whitespace validation. Use `php scripts/check_release.php` independently only while diagnosing/preparing consistency. Inspect skipped/blocked coverage and the final package before publication. Release tooling never creates commits, tags, pushes, or hosted releases unless those actions are explicitly requested.

The runner discovers every tracked `tests/*_test.php` script deterministically. Individual scripts can still be run directly when isolating a behavior. The project intentionally has no Composer or PHPUnit dependency.

## Troubleshooting

### Gallery Not Appearing After Upload

1. Go to **Discovery** and click **Scan for new galleries**
2. Select the folder and click **Import**
3. Check that folder is in the configured `galleries_root`
4. Verify folder has readable image files

### Thumbnails Not Generating

1. Check that GD extension is installed: `php -m | grep gd`
2. Go to **Thumbnails** and try manual regeneration
3. Check folder permissions: `galleries/` must be writable
4. Review admin log for error messages

### Admin Password Lost

If you lose the admin password and can't log in:

1. Use the password reset email feature (if configured)
2. Or visit `?page=setup&key=YOUR_SETUP_KEY` if you know the setup key
3. Or use CLI: `php scripts/create_admin.php newusername newpassword`

### Database Connection Error

1. Verify credentials in `config.php` match your database
2. Check database server is running
3. Verify user has correct permissions on the database
4. For shared hosting, use localhost or 127.0.0.1

### Pretty URLs Not Working

1. Verify Apache `.htaccess` support is enabled
2. Check rewrite module: `apache2ctl -M | grep rewrite`
3. Query-string routes always work (fallback): `?page=gallery&slug=...`

## Smart Galleries

Smart Galleries are saved, dynamic image queries. Create one from **Admin > Galleries > Smart Galleries**, combine conditions with nested **AND**, **OR**, and **NOT** groups, preview real matching cards, then optionally publish it. Images stay in their original galleries and filesystem locations; metadata or private editorial-rating changes alter membership immediately.

Published Smart Galleries use `?page=smart_gallery&slug=...` everywhere and `/smart/<slug>` when clean URLs are enabled. Root and physical-gallery placements expose only enabled, published Smart Galleries. Public counts, covers, page rows, lightbox metadata, and downloads are generated from one service-layer result query that intersects the Smart Gallery rules with public/listed physical galleries the current visitor may actually access, public image visibility, and NSFW policy. Private, locked, unpublished, share-only-without-valid-access, and otherwise inaccessible source content does not contribute to counts or URLs.

Placement can remain **Unlisted** for URL-only access, appear as a **Root gallery** on the homepage, or use **Subgallery placement**. For each physical parent, an attachment independently stores **Above gallery content** or **Below gallery content** plus an integer order. Top attachments render before normal subgalleries/photos, bottom attachments render afterward, and equal order values are resolved by Smart Gallery ID. Existing and newly created attachments default to bottom. The same Smart Gallery may be attached beneath any number of physical galleries, while the composite relationship prevents duplicate instances under one parent. Migration `202608170002_smart_gallery_attachment_ordering.php` adds the per-parent placement/order columns. Until it is applied, existing attachments continue to read as bottom and attachment mutations are refused rather than partially saved.

Each Smart Gallery can inherit the current Theme/site presentation or persist its own versioned presentation overrides for grid columns/rows, pagination, thumbnail bounds and renderer, gallery-card layout, metadata overlays, lightbox, lightbox mode, slideshow, download, and voting. Sorting remains the existing allowlisted Smart Gallery sort mode/direction. Presentation does not inherit from a physical placement because a Smart Gallery can have multiple parents. Invalid or missing presentation values fall back to current Theme/site defaults. Migration `202608170001_smart_gallery_presentation.php` adds the nullable `presentation_json` storage.

Lightbox navigation is not limited to the current HTML page. Cards carry global result indexes and the existing sparse lightbox cache requests authorized nearby metadata from `smart_gallery_lightbox_data` in windows capped at 80 records. The endpoint uses the same rules, access predicate, sort, direction, and image-id tie breaker as public rendering, so previous/next movement crosses pagination boundaries without preloading the full result set or original files. Normal gallery lightbox behavior is unchanged.

The Admin editor works as a full-page server form and through the existing right-side drawer. Side-panel saves reuse the normal POST controller and re-inject the returned editor without a page reload or URL change. Physical gallery editing exposes every attachment's placement/order; Smart Gallery editing shows the same values independently for every parent and allows in-place save or detach. Plain POST remains the no-JavaScript fallback. The preview renders real matching cards with effective presentation settings. Smart Gallery ZIP downloads reuse the same authorized result query, refuse more than 5,000 matching images, and also enforce an aggregate source-byte cap. Existing installations use 2 GiB unless `smart_gallery_zip_max_source_bytes` is set in `config.php`. Concurrent requests for the same archive signature are serialized and the finished ZIP is published atomically from a unique partial file.

Relationship validation builds a request-cached graph from Smart Gallery attachment edges and positive physical-gallery references in Smart Gallery rules, using the current or proposed physical hierarchy to expand `under` references to their descendant branch while keeping exact references exact. Proposed rules, attachments, ordinary hierarchy moves, filesystem-derived parent synchronization, and public-path parent repair are validated before new hierarchy writes. Complete Admin drag-and-drop trees are validated once as the final parent map, then moved through a prevalidated batch path so temporary intermediate states do not cause false cycle failures. Every committed physical hierarchy mutation clears the request-local graph cache. Legacy malformed/cyclic rows are skipped in public placement rendering and reported in Admin rather than recursively expanded. Traversal is bounded by depth, expanded-node, Smart-Gallery-node, source-row, edge, and per-parent attachment ceilings. The current rule catalog has no Smart-Gallery-to-Smart-Gallery reference field, so no unsupported relation is inferred.

See **[docs/SMART_GALLERIES.md](docs/SMART_GALLERIES.md)** for the service, access, presentation, lightbox, Admin, and download contracts.

## Support & Resources

- **Source Code:** https://github.com/klusik/PHP_gallery
- **License:** MIT (see LICENSE file)
- **Author:** Rudolf Klusal (@klusik)

For issues, questions, or contributions, please open an issue on GitHub.

## Changelog

See **[PATCH_NOTES.md](PATCH_NOTES.md)** for detailed version history and recent changes.

---

**PHP Gallery CMS** - Simple, powerful, and designed for ordinary shared hosting.
