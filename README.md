# PHP Gallery CMS

A modern PHP 8.0+ gallery CMS designed for ordinary shared hosting. The application uses the filesystem as the authoritative source for gallery structure, while storing all metadata, access rules, votes, user accounts, and audit logs in MySQL or MariaDB.

**Current Version:** 0.88

**Key Benefit:** Deploy in minutes on shared hosting. No npm, no Composer, no framework overhead. Just PHP + MySQL.

## Core Features

### Gallery Management
- **Filesystem-first design** - Galleries are folders on disk; the database mirrors and enhances them
- **Nested galleries** - Create gallery hierarchies with unlimited depth
- **Gallery discovery** - Automatically detect and import new folders
- **Manual creation** - Create empty gallery folders from the admin interface
- **Bulk operations** - Rename, delete, move, reorder, or change visibility for multiple galleries at once
- **Gallery metadata** - Title, description, optional date range, cover image, custom slug
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
- **Two public thumbnail renderers** - Responsive browser selection remains the default; selected-gallery photo cards can optionally use progressive small-first sharpening from Admin > Theme > Layout
- **Quality tuning** - Configurable JPEG quality with automatic size optimization
- **DNG RAW support** - Generate display masters from DNG raw files
- **Lazy loading** - Deferred thumbnail loading in fullscreen gallery
- **Maintenance tools** - Regenerate, delete, or verify thumbnail cache
- **Admin log scalability** - Browse grouped operational events, export large histories in bounded batches, and keep recent records in the live database while archiving older days safely

#### Public thumbnail rendering modes

The site-level `public_thumbnail_rendering_mode` setting controls photo cards inside a selected gallery and accepts only `responsive` or `progressive`. Missing or invalid values resolve to `responsive`. Gallery cover cards, subgallery collage cells, home-page gallery cards, and Admin thumbnails continue to use responsive browser selection.

**Responsive browser selection - Default** renders normal server-side `<picture>/<img>` markup with the complete available WebP/JPEG candidate set immediately. The browser can select among generated 300, 600, 800, and 960 px candidates, subject to actual derivatives and thumbnail bounds. It works fully without JavaScript.

**Progressive thumbnail sharpening - Beta** is the current Admin-facing label for the second permanent pipeline. PHP still renders a real small thumbnail, normally the 300 px derivative, plus semantic links and alt text. Larger candidates remain inert until the optional browser module sees a visible or near-visible card, measures it, and preloads/decodes the smallest adequate replacement. JavaScript-disabled visitors keep the functional small thumbnail.

Progressive rendering prioritizes perceived initial responsiveness, not minimum total transfer. A visitor can download both the initial small thumbnail and a later larger replacement, so total transferred bytes can be higher than responsive mode even when the page feels ready sooner. The word Beta describes current Admin-facing maturity only; it is not part of setting keys, renderer identifiers, filenames, or architecture terminology.

### Access Control
- **Visibility modes** - Public, unpublished (admin-only), or private (password/token protected)
- **Password protection** - Per-gallery passwords with session-scoped unlock
- **Share links** - Generate time-limited or permanent share tokens
- **Inheritance** - Child galleries inherit parent access rules
- **Admin-only zones** - Some galleries only visible when logged in

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
- **Fullscreen viewer** - Lightbox with keyboard navigation, zoom, EXIF overlay
- **Picture game** - Side-by-side image comparison game (optional per-gallery)

### Downloading
- **ZIP archives** - Download a single gallery or all accessible galleries
- **Streaming** - Archives streamed directly (not stored on disk)
- **Signed downloads** - Optional signature verification for secure links

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
- **Backup on update** - Overwritten files preserved under `cache/updates/backups`
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
- Optional browser-side preparation with client thumbnails, EXIF metadata, bounded ZIP batches, and server-side validation
- Automatic fallback to normal server-side uploads when browser preparation is unavailable

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
- PHP 8.0 or newer
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

Version 0.88 adds a Spotlight-style search directly below the Settings title. It searches the complete global configuration registry while typing, including discovery-only entries for specialized Theme, upload, telemetry, thumbnail, maintenance, navigation-data, feature, database, account, mail, Google, and OpenAI controls. Results use contextual labels and descriptions, support keyboard navigation, and open the canonical owning page without copying secret values or duplicating specialist persistence.

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

1. Go to **Updates** to check GitHub for new versions
2. See available versions and read patch notes
3. Click **Update** to download and install
4. The updater validates required release files before touching the installation
5. Incoming files are staged, size-checked, and activated before obsolete managed files are removed
6. Backups of overwritten and removed files are saved under `cache/updates/backups/`
7. Your `config.php`, galleries, and custom CSS are never overwritten

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

`app/services/translations.php` restricts language selection to the maintained set above. Additional JSON skeletons may remain under `app/lang/` for future translation work, but simply placing a file there does not make that language selectable. English remains the runtime fallback if a maintained translation ever lacks a key.

Admin and public language choices are independent:

- **Admin interface language** is stored for the administrator in the session and browser cookie.
- **Public visitor language** is a site-wide default stored in application settings.
- Public requests may override the site default with a supported `?lang=<code>` value; the visitor override is remembered in a public-language cookie.
- Changing the interface language does not translate gallery titles, descriptions, tags, photo captions, or other owner-authored content.

Editable catalogs live in `app/lang/<code>.json`. JSON is the maintained format. The `en.php`, `cs.php`, `de.php`, and `sv.php` dictionaries remain compatibility fallbacks and are loaded only when a JSON pack for that code does not exist. Theme > Language exposes only the currently supported four languages in the Admin and Public selectors and pack editor, reports key coverage against English, and provides JSON edit/import/export tools plus missing-key diagnostics.

Current selectable language packs:

| Code | Display name | Current role |
| --- | --- | --- |
| `en` | English | Complete canonical source, default, and fallback |
| `cs` | Čeština | Complete maintained selectable translation |
| `de` | Deutsch | Complete maintained selectable translation |
| `sv` | Svenska | Complete maintained selectable translation |

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
php tests/run.php
```

The runner currently executes 28 focused PHP tests covering gallery models, paths, migrations, uploads, thumbnails, public assets, URL rewrites, AI settings, and updater safety. Individual scripts can still be run directly when isolating a behavior. The project intentionally has no Composer or PHPUnit dependency.

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

## Support & Resources

- **Source Code:** https://github.com/klusik/PHP_gallery
- **License:** MIT (see LICENSE file)
- **Author:** Rudolf Klusal (@klusik)

For issues, questions, or contributions, please open an issue on GitHub.

## Changelog

See **[PATCH_NOTES.md](PATCH_NOTES.md)** for detailed version history and recent changes.

---

**PHP Gallery CMS** - Simple, powerful, and designed for ordinary shared hosting.
