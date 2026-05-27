# Architecture

PHP Gallery CMS is a modern plain-PHP application (v0.66+) designed to run reliably on ordinary shared hosting without frameworks, build steps, or external dependencies.

## Core Principles

- **No framework overhead** - Direct request/response, minimal abstraction
- **Filesystem-first** - Galleries are folders on disk; database mirrors metadata
- **Database as source of truth for metadata** - Access rules, visibility, tags, votes, settings
- **Shared hosting compatible** - Works with basic Apache + MySQL on low-cost hosting
- **Clean separation** - Controllers handle HTTP, services handle business logic
- **Type safety** - Strict types enabled throughout, PHP 8.0+ features used
- **Focused modules** - Large files split into smaller, single-responsibility controllers and services

## Request Flow

### Entry Point

1. **`index.php`** (root) delegates to **`public/index.php`** (web root)
2. **`public/index.php`** loads **`app/bootstrap.php`**
3. **`app/bootstrap.php`** initializes the application:
   - Loads configuration
   - Starts session with security headers
   - Maps incoming request to a route
   - Dispatches to the appropriate controller function

### Routing

The router (`cms_route_from_request()`) supports two parallel systems:

**Query-string routes** (always work, backward compatible):
```
/index.php?page=gallery&slug=vacation
/index.php?page=admin&action=edit_gallery
```

**Pretty URLs** (with Apache `.htaccess` rewrite rules, optional):
```
/gallery/vacation/
/admin/?action=edit_gallery
/download/vacation.zip
```

Both systems populate `$_GET['page']` and other parameters identically, ensuring controllers work regardless of rewrite availability.

### Route Table

The complete routing table is defined in `cms_run()` in `app/bootstrap.php`. Key routes:

**Public Gallery Routes:**
- `home` - Lists public top-level galleries
- `gallery` - Renders one gallery with images, subgalleries, tags, votes
- `tag` - Renders galleries filtered by a tag
- `picture_game` - Side-by-side image comparison (optional, gallery-specific)
- `gallery_map_data` - Returns JSON GPS points for maps
- `media` - Streams image file with visibility checks
- `thumb` - Streams generated JPEG thumbnail
- `public_media` - Legacy public media endpoint
- `public_thumb` - Legacy public thumb endpoint
- `robots` - `robots.txt` for SEO
- `sitemap` - `sitemap.xml` for search engines
- `share` - Validates share token for protected gallery
- `gallery_access` - Validates gallery password
- `download_gallery` - Creates ZIP archive of one gallery
- `download_all` - Creates ZIP archive of all accessible galleries
- `vote` - AJAX endpoint for image/gallery voting
- `exif` - Returns EXIF data for an image (if enabled)

**Asset Routes:**
- `theme_css` - Dynamically generated admin theme CSS
- `gallery_cover_asset` - Gallery cover image uploads
- `gallery_branding_asset` - Gallery branding (logo, colors)
- `theme_background_asset` - Theme background images
- `theme_branding_asset` - Site-wide branding
- `favicon_asset` - Dynamic favicon

**Admin Routes:**
- `admin` - Main dashboard (discovery, bulk actions, settings overview)
- `admin_login` - Login form
- `admin_logout` - Logout (destroys session)
- `admin_forgot_password` - Password recovery initiation
- `admin_reset_password` - Password reset form
- `admin_account` - Admin user settings, email, password
- `admin_theme` - Theme customization, language, gallery layout defaults
- `admin_discover` - Find new galleries on filesystem
- `admin_import` - Import discovered galleries
- `admin_new_gallery` - Create empty gallery folder and DB entry
- `admin_edit_gallery` - Edit gallery metadata (name, description, visibility, etc.)
- `admin_bulk_galleries` - Bulk rename/delete/move galleries
- `admin_reorder_galleries` - Reorder gallery hierarchy
- `admin_reorder_public_galleries` - Reorder public gallery display order
- `admin_reorder_images` - Reorder images within a gallery
- `admin_bulk_images` - Bulk image operations
- `admin_edit_image` - Edit image caption, tags, metadata
- `admin_upload` - Upload images to a gallery
- `admin_tags` - Manage reusable tags, metadata, descriptions
- `admin_thumbnails` - Generate/regenerate/delete thumbnails
- `admin_scan_images` - Scan gallery folder for new/modified files
- `admin_integrity` - Verify database consistency against filesystem
- `admin_logs` - View admin action audit log
- `admin_log_update` - Modify log visibility/status
- `admin_log_export` - Export logs as JSON/CSV
- `admin_logs_export_zip` - Export logs as ZIP archive
- `admin_telemetry` - View usage statistics and telemetry
- `admin_telemetry_settings` - Telemetry preferences
- `admin_telemetry_export` - Export telemetry data
- `admin_update` - Check for and install application updates
- `admin_reset` - Emergency recovery (restore from stable branch)
- `admin_run_migrations` - Run pending database migrations
- `admin_create_thumbnails` - Generate missing thumbnails (wizard)
- `admin_delete_thumbnails` - Delete cached thumbnails
- `admin_dismiss_thumbnail_notice` - Hide thumbnail rebuild notice
- `admin_regenerate_paths` - Regenerate public URL slugs
- `admin_save_gallery_collapse` - Save admin panel expand/collapse state
- `admin_devmode` - Development/debug information
- `admin_public_update_gallery` - Inline edit gallery from public page
- `admin_public_update_image` - Inline edit image from public page

**Special Routes:**
- `setup` - First-run installer (runs when `config.php` missing)
- `telemetry_ingest` - Receives anonymous usage statistics
- `usage_collect` - Alias for telemetry_ingest

## Files & Organization

### Root Directory
```
index.php                    Main entry point (delegates to public/index.php)
public/index.php             Front controller for web server
config.php                   Generated on install (database, paths, settings)
config.example.php           Example configuration template
install.php                  Standalone installer (runs before app ready)
setup-gallery.php            Bootstrap installer (one-file setup)
reset.php                    Emergency recovery endpoint
.htaccess                    Apache rewrite rules for pretty URLs
```

### `app/` - Application Core
```
app/
  bootstrap.php              Initialization, routing, dispatcher
  controllers.php            Module loader for controllers
  controllers/               Refactored controller files
    admin_auth.php           Login, logout, password reset, sessions
    admin_galleries.php      Loader for gallery controller modules
    admin_galleries_*.php    Focused controllers (discovery, edit, bulk, reorder)
    admin_gallery_renderers.php  Shared HTML rendering helpers
    admin_images_*.php       Image reordering, bulk actions
    admin_tags.php           Tag management, metadata editing
    admin_theme.php          Theme customization, language, layout
    admin_thumbnails.php     Thumbnail generation, management, quality
    admin_dashboard.php      Main admin dashboard
    admin_logs.php           Audit log viewing and export
    admin_integrity.php      Database consistency checking
    admin_uploads.php        File upload handling, scanning
    admin_public_inline.php  Inline editing from public gallery pages
    public_gallery.php       Public gallery rendering (images, lightbox, voting)
    public_media.php         Media streaming and handling
    public_tags.php          Public tag listing and filtering
    theme_assets.php         Dynamic asset serving (CSS, images, favicon)
    downloads.php            ZIP archive creation
    updates.php              Update checking and installation
    picture_game.php         Side-by-side image comparison
    tags.php                 Legacy tag page loader
    exif.php                 EXIF metadata extraction
    setup.php                Installer logic
    telemetry.php            Anonymous stats collection
    http_helpers.php         Utility functions for controllers
  
  helpers.php                URL helpers, escaping, rendering utilities
  security.php               Sessions, CSRF, admin auth, visitor hashing
  database.php               PDO connection factory
  migrations.php             Migration runner and tracking
  services.php               Service loader and registry
  services/                  Focused service modules
    gallery_*.php            Gallery queries, mutations, metadata
    image_scanning.php       Filesystem scanning for images
    thumbnail_*.php          Thumbnail generation and management
    tag*.php                 Tag operations and metadata
    vote*.php                Voting/scoring operations
    download*.php            ZIP creation and signatures
    upload*.php              File upload validation
    telemetry*.php           Anonymous statistics collection
    theme.php                Theme settings and CSS
    exif.php                 EXIF extraction
    pagination.php           Paginated queries
    logs.php                 Admin log storage
    auth_throttle.php        Login/password-reset rate limiting
    translations.php         Language selection and fallbacks
    ...and many more focused service files
  
  lang/                      Translation files
    en.json                  English strings
    cs.json                  Czech strings
    en.php                   English (fallback)
    cs.php                   Czech (fallback)
  
  integrity.php              File integrity checking
  core-manifest.json         Integrity manifest (auto-generated)
```

### `database/` - Database Schema
```
database/
  migrations/                Numbered, sequential migration files
    202604270001_initial_schema.php       Base schema (users, galleries, images, etc.)
    202604270002_tags_and_theme.php       Tag tables, theme settings
    202604280001_picture_game.php         Picture game metadata
    202604280002_admin_logs_status.php    Admin log status column
    202604280003_exif_gps_maps.php        GPS/map metadata
    202604290001_password_protected_galleries.php  Protected gallery schema
    202604290002_persistent_share_links.php       Share token management
    202604300001_gallery_voting.php       Voting/scoring
    ...and more recent migrations for features and refinements
```

### `public/` - Web Root Assets
```
public/
  index.php                  Front controller (delegates to app/bootstrap.php)
  .htaccess                  Access control for Apache
  assets/
    styles.css               Main stylesheet (legacy, pre-split)
    gallery.js               Main JavaScript (legacy, re-exports modules)
    telemetry.js             Anonymous stats collection
    usage.js                 Usage tracking
    gallery-modules/         Modern ES6 modules
      admin-bulk-actions.js  Bulk operation UI
      admin-core.js          Re-export of admin functions
      admin-date-picker.js   Calendar date selection widget
      admin-gallery-list.js  Gallery list management
      admin-image-reordering.js  Drag-and-drop image sorting
      admin-logs.js          Log filtering UI
      admin-operations.js    Legacy admin function export
      admin-refresh-progress.js  Progress indicators
      admin-side-panel.js    Side panel form handling
      admin-tabs.js          Tab switching
      admin-thumbnail-progress.js  Thumbnail generation progress
      back-to-top.js         Scroll-to-top button
      favicon-cropper.js     Favicon upload handling
      lightbox-deferred.js   Lazy-load lightbox
      lightbox-votes.js      Vote form sync in lightbox
      lightbox.js            Fullscreen image viewer
      responsive-thumbnails.js  Adaptive thumbnail sizing
      tag-suggestions.js     Auto-complete tag input
      theme-form.js          Theme editor form
      votes.js               Voting UI
    styles/                  Modern CSS modules
      admin-*.css            Admin area stylesheets
      base.css               Base styles
      lightbox.css           Lightbox/fullscreen styles
      public.css             Public gallery styles
      utilities.css          Utility classes
```

### `galleries/` - User Content
```
galleries/                   Gallery folder root (configurable)
  vacation/                  Gallery folder (contains images)
    image1.jpg
    image2.jpg
    subfolder/               Subgallery
      image3.jpg
      gallery.json           Optional metadata (title, description, tags)
  ...
```

### `cache/` - Generated Files
```
cache/
  .htaccess                  Prevent direct access
  installed.lock             Marks app as installed
  bootstrap-installed.lock   Marks bootstrap installer completed
  thumbnails/                Generated thumbnail cache
  zip/                       Temporary ZIP archives
  ...
```

### `custom_css/`
```
custom_css/
  custom.css                 Admin theme customizations
  modern.css                 Modern theme stylesheet
  css_template.css           Theme CSS template
```

## Data Model

### Core Tables

**`users`**
- Admin account(s) with username, email, password hash
- Currently supports single role: `admin`
- Optional password reset flow with email delivery

**`galleries`**
- Mirrors filesystem folders under `galleries/` root
- Key columns:
  - `id` - Internal ID
  - `folder_path` - Relative path from galleries root
  - `folder_path_hash` - SHA256 hash of path (fast lookup)
  - `parent_id` - NULL for top-level, otherwise parent gallery ID
  - `title`, `description` - Gallery metadata
  - `visibility` - public/private/draft
  - `public_slug` - URL-safe slug for pretty URLs
  - `cover_image_id` - Image ID for gallery card thumbnail
  - `cover_image_path` - Uploaded cover image path
  - `gallery_date` - Optional admin-set date
  - `sort_order` - Display order
  - `show_filenames` - Whether to expose raw filenames in captions
  - `description_layout` - vertical/horizontal subgallery card layout
  - Access control fields (password hash, share tokens, etc.)
  - Created/updated timestamps

**`images`**
- Image files within galleries
- Key columns:
  - `id` - Internal ID
  - `gallery_id` - Parent gallery
  - `filename` - Original filename
  - `title` - Admin-editable title
  - `caption` - Admin-editable caption
  - `vote_score` - Aggregated votes
  - `sort_order` - Display order
  - Metadata: dimensions, file size, upload date, EXIF data
  - Created/updated timestamps

**`tags`, `gallery_tags`, `image_tags`**
- Reusable tags with optional descriptions
- Tag metadata: display name, slug, description, usage counts
- Gallery and image associations through junction tables

**`image_votes`**
- One row per user/visitor per image
- Stores +1/-1 votes
- Anonymous votes hash visitor by IP/cookie
- Vote scores computed as aggregates

**`admin_logs`**
- Audit trail of admin actions
- Columns: action type, subject (gallery/image), timestamp, user
- Diagnostic fields: HTTP method, AJAX flag, request fingerprint
- Used for troubleshooting and compliance

**`admin_settings`**
- Key-value store for admin preferences
- Theme choice, language, layout defaults, column visibility

**`telemetry`**
- Optional anonymous usage statistics
- Gallery counts, image counts, feature usage
- Privacy-respecting, can be disabled

### Relationships

```
users
  ↓ (admin of)
galleries (parent_id creates hierarchy)
  ↓ (contains)
images
  ↓ (voted on by)
image_votes

galleries ↔ tags (through gallery_tags)
images ↔ tags (through image_tags)

admin_logs (references galleries, images, users)
telemetry (anonymous aggregates, no personal data)
```

## Key Features & Their Implementations

### Gallery Discovery & Import
- **Controller:** `admin_galleries_discovery.php`
- **Service:** `image_scanning.php`, `gallery_lookup.php`, `gallery_mutations.php`
- **Workflow:** Scan `galleries/` folder → detect new folders → optionally import with thumbnail creation

### Image Upload & Scanning
- **Controller:** `admin_uploads.php`
- **Services:** `uploads.php`, `image_scanning.php`, `thumbnail_generation.php`
- **Workflow:** Validate file → move to gallery folder → scan for images → optionally create thumbnails

### Thumbnail Generation
- **Controllers:** `admin_thumbnails.php`
- **Services:** `thumbnail_generation.php`, `thumbnail_formats.php`, `thumbnail_sources.php`, `thumbnail_bundles.php`, `thumbnail_maintenance.php`, `dng_derivatives.php`
- **Features:** 
  - JPEG + WebP variants
  - Quality bounds and responsive sizing
  - DNG RAW display masters
  - Deferred/batch generation
  - Maintenance and cleanup

### Public Gallery Rendering
- **Controller:** `public_gallery.php`
- **Services:** `gallery_display.php`, `gallery_lookup.php`, `gallery_access.php`, `gallery_covers.php`, `pagination.php`
- **Features:**
  - Breadcrumbs for nested galleries
  - Subgallery cards with cover images
  - Image grid with responsive thumbnails
  - Lightbox/fullscreen viewer with EXIF overlay
  - Voting/scoring UI
  - Tag filtering
  - SEO metadata (canonical, OG, JSON-LD)

### Gallery Access Control
- **Service:** `gallery_access.php`
- **Types:**
  - Public (anyone can view)
  - Draft (admin only)
  - Private/Protected (password or share link)
- **Share Links:** Generate time-limited, single-use tokens
- **Password Protection:** Per-gallery passwords, session-scoped unlock

### Voting & Scoring
- **Services:** `votes.php`
- **Features:**
  - Per-image and per-gallery scores
  - +1/-1 voting
  - Anonymous visitor hashing (IP-based)
  - Logged-in user voting (session-based)
  - Vote persistence

### Tagging
- **Service:** `tags.php`, `tag_metadata.php`
- **Features:**
  - Reusable tags with metadata
  - Admin tag management
  - Tag auto-complete in edit forms
  - Public tag pages
  - Gallery/image filtering by tags
  - Aggregated "containing tags" for parent galleries

### ZIP Downloads
- **Service:** `downloads.php`
- **Features:**
  - Download single gallery as ZIP
  - Download all accessible galleries as ZIP
  - Streaming (not stored on disk)
  - Signature verification for secure links

### Audit Logging
- **Service:** `logs.php`
- **Features:**
  - All admin actions logged (create, edit, delete, upload, etc.)
  - Diagnostic data (HTTP method, AJAX flag, fingerprint)
  - Search/filter by action, gallery, image, date
  - Export as JSON/CSV/ZIP
  - Log retention policy (optional)

### Telemetry & Statistics
- **Services:** `telemetry.php`, `telemetry_privacy.php`, `telemetry_settings.php`, `telemetry_rollup.php`
- **Features:**
  - Anonymous usage collection (opt-in)
  - No personal data collected
  - Gallery/image counts, feature usage
  - Admin dashboard statistics
  - Privacy controls

### Theme Customization
- **Controller:** `admin_theme.php`
- **Services:** `theme.php`, `gallery_branding.php`, `gallery_backgrounds.php`
- **Features:**
  - Admin color/font customization
  - Language selection (English, Czech, extensible)
  - Gallery-specific branding (logo, background, cover)
  - Custom CSS editor
  - Dark/light mode support
  - Default layout for gallery cards

### Updates
- **Controller:** `updates.php`
- **Service:** `updates.php`
- **Features:**
  - Check GitHub for newer releases
  - Beta/stable branch selection
  - Download and install updates
  - Backup modified files before update
  - Cached release notes

### Migrations
- **Service:** `migrations.php`
- **Features:**
  - Numbered, sequential migrations
  - Tracking table to prevent re-runs
  - Forward-compatible schema evolution
  - Admin UI to run pending migrations

## Performance Optimizations

### Caching
- **Browser caching:** Static assets with cache headers
- **Thumbnail caching:** Generated thumbnails stored on disk
- **Database query caching:** App-level caching for repeated queries
- **ZIP stream generation:** Not stored; streamed directly to client

### Database
- **Indexes:** On frequently queried columns (folder_path_hash, gallery_id, etc.)
- **Query optimization:** Minimal joins, pagination for large galleries
- **Connection pooling:** PDO persistent connections (optional)

### Images & Thumbnails
- **Responsive thumbnails:** Multiple sizes/formats for different contexts
- **WebP support:** Modern browsers get smaller, faster files
- **Quality bounds:** Configurable JPEG quality vs. size tradeoff
- **Lazy loading:** Thumbnails load deferred in fullscreen

### Frontend
- **ES6 module system:** Code splitting for smaller JS downloads
- **Responsive CSS:** Mobile-first media queries
- **Minimal HTTP requests:** Bundled CSS/JS, inline SVGs for icons

## Security

### Input Validation
- **File uploads:** MIME type checking, extension whitelist, size limits
- **Database:** Parameterized queries with bound variables (PDO)
- **User input:** HTML escaping with `e()` helper, CSRF protection

### Authentication
- **Sessions:** Secure cookies (HttpOnly, SameSite, HTTPS-only)
- **Password reset:** Rate-limited, token-based flow
- **Multi-admin support:** Per-user audit logging

### Authorization
- **Gallery access:** Visibility rules enforced on all queries
- **Password-protected galleries:** Validated before rendering
- **Share links:** Token validation, expiration checking

### Attack Prevention
- **CSRF:** Token validation on all state-changing requests
- **SQL injection:** Parameterized queries throughout
- **XSS:** Output escaping on all user-controlled content
- **Path traversal:** Validated folder paths, no `..` allowed

### Integrity
- **File integrity checking:** Manifest of core application files
- **Database integrity:** Optional consistency checks

## Development

### Code Organization
- **Strict types:** `declare(strict_types=1)` in all PHP files
- **Type hints:** Function parameters and return types
- **Naming conventions:** Clear, descriptive function names
- **Documentation:** Header comments explaining module purpose
- **Version tracking:** Each file timestamped

### Extending the Application
1. **New feature:** Create new controller file in `app/controllers/`
2. **Business logic:** Add service file in `app/services/`
3. **Database changes:** Create numbered migration in `database/migrations/`
4. **Translations:** Add strings to `app/lang/` JSON files
5. **Register routes:** Add entry to `$routes` table in `bootstrap.php`

### Testing
- Test files in `tests/` directory
- Unit tests for data model behavior
- Run with standard PHP test runner (PHPUnit, etc.)

### Deployment
- **Single-file installer:** `setup-gallery.php` for shared hosting
- **Manual deployment:** Upload with FTP, run installer
- **Zero config:** Configuration auto-generated on install
- **Zero build step:** No npm, Composer, or pre-processing needed

## Summary

PHP Gallery CMS v0.66+ is a focused, well-organized application with:

- **Clear request/response flow:** Routing → Controllers → Services → Views
- **Focused responsibility:** Controllers handle HTTP, Services handle logic
- **Modular design:** 50+ service files, 30+ controller modules
- **Database-backed state:** Galleries, images, tags, votes, logs
- **Filesystem-first:** Galleries are folders; DB stores metadata
- **Rich feature set:** Discovery, upload, tagging, voting, access control, logs, stats
- **Shared hosting ready:** No dependencies, simple deployment
- **Modern PHP:** Type hints, strict mode, PDO, security best practices

The application is built for reliability, maintainability, and ease of deployment on ordinary shared hosting.

## SimBrief OFP Route Visualization and Local Fallback Data

PHP Gallery uses a SimBrief-first route visualization workflow for flight-route maps. The feature is intended for simulation gallery visualization, waypoint display, airport display, SimBrief route rendering, and future lightweight planning overlays. It is not a certified flight-planning, dispatch, or FMC replacement.

### Data Strategy

Generated route maps prefer saved SimBrief OFP data:

1. The gallery editor asks SimBrief for the latest OFP by Pilot ID or pilot name.
2. The decoded OFP JSON is saved as `simbrief-ofp.json` inside the gallery folder.
3. A compact `simbrief-ofp-manifest.json` is saved beside it with origin, destination, aircraft, AIRAC, and route-point metadata.
4. Route coordinates are extracted from the OFP origin, navlog, and destination data.
5. The resolved point list is saved in `gallery_flight_maps.resolved_points_json`.
6. Public maps render only stored coordinates.

The local navdata layer remains available for manually entered route text. It uses the existing `flight_map_nav_points` table populated by the OurAirports importer and `data/navdata/local_nav_points.csv` as a compact bundled fallback dataset.

### Public Display

Public route-map rendering is cache-friendly and provider-free:

1. The public map endpoint reads only `gallery_flight_maps` and gallery-owned files.
2. Stored route geometry is returned to the Leaflet renderer.
3. The renderer draws one polyline from departure to arrival.
4. Departure and arrival receive normal pins.
5. Intermediate OFP route points receive very small triangle markers so the route remains readable without clutter.

No live SimBrief, Navigraph, AIRAC, or external route lookup request is made while a visitor views the public gallery.

### Fallback Model

The resolver degrades in this order:

1. Saved SimBrief OFP route coordinates for generated maps
2. Stored `gallery_flight_maps` coordinates for public display
3. Admin-imported local DB navdata for manual route text
4. Bundled offline CSV fallback points
5. Manual `NAME@latitude,longitude` route entries

If SimBrief is unavailable later, already-saved gallery OFP files and stored route coordinates remain usable.

### Extension Points

Future work can add:

- Re-reading saved OFP files to regenerate route maps after renderer improvements
- SimBrief flight-history selection instead of latest-OFP only
- A route preview panel in the gallery editor
- Larger offline datasets imported into `flight_map_nav_points`
- Spatial lookup endpoints for nearby airports or navaids
- Lightweight airway expansion for manual route text
- Route editor autocomplete backed by `navdata_lookup`
