# Architecture

PHP Gallery CMS is a small plain-PHP application. It avoids a framework, build
step, and Composer dependencies so it can run on ordinary shared hosting.

## Request Flow

`index.php` delegates to `public/index.php`, which loads `app/bootstrap.php`.
The bootstrap file loads helpers, database access, security helpers, migrations,
services, and controllers. `cms_run()` maps the `page` query parameter or a
pretty URL to a controller function.

Important routes:

- `page=home` lists public top-level galleries.
- `page=gallery&slug=...` renders one gallery, its images, subgalleries, tags,
  votes, breadcrumbs, and lightbox data.
- `page=gallery_access` validates a protected-gallery password and records a
  short public unlock in the visitor session.
- `page=share&id=...&token=...` validates a share token for one protected
  gallery and redirects to that gallery.
- `page=tag&slug=...` renders a public gallery listing filtered by one tag.
- `page=picture_game&id=...` runs the optional side-by-side picture comparison
  game for opted-in public gallery branches.
- `page=gallery_map_data&id=...` returns JSON map points for the current public
  gallery branch when GPS maps are enabled.
- `page=media&id=...` streams an image through PHP after visibility checks.
- `page=thumb&id=...&size=...` streams a generated JPEG thumbnail after the
  same visibility checks.
- `page=admin` is the dashboard for discovery, scans, bulk actions, and edits.
- `page=admin_theme` stores theme controls and optional custom CSS.
- `page=admin_update` checks GitHub for newer releases and can install the
  configured branch archive after creating a backup of overwritten files.
- `page=admin_run_migrations` runs pending migrations from an authenticated
  admin POST when the dashboard detects a stale schema.
- `page=admin_public_update_gallery` and `page=admin_public_update_image` save
  admin-only inline edits submitted from public gallery pages.
- `install.php` is standalone and can create config, DB tables, folders, and the
  first admin account before the normal app is ready.

The app supports two web-root layouts. The repository root can be served
directly, in which case root `index.php` delegates to `public/index.php`; or the
server can point directly at `public/`. Query-string routes work in both layouts,
and Apache rewrite rules add nicer URLs when `.htaccess` is enabled.

## Files

- `app/bootstrap.php`: loads the app and dispatches routes.
- `app/database.php`: creates the shared PDO connection.
- `app/helpers.php`: rendering helpers, URL helpers, escaping, path helpers.
- `app/security.php`: sessions, current user lookup, CSRF, visitor vote hash.
- `app/services.php`: filesystem discovery, imports, scans, tags, votes,
  settings, covers, ZIP creation, and database lookup helpers.
- `app/controllers.php`: page handlers and HTML rendering for public/admin UI.
- `database/migrations/`: ordered PHP files returning SQL statements.
- `public/assets/styles.css`: built-in themeable stylesheet.
- `public/assets/gallery.js`: voting AJAX, tag suggestions, admin tree controls,
  select-all controls, and lightbox behavior.
- `deploy.bat` and `scripts/deploy.ps1`: optional FTP/local deployment helpers.
- `.htaccess`, `public/.htaccess`, `cache/.htaccess`, `galleries/.htaccess`:
  routing and direct-access protection for Apache hosting.

## Data Model

`galleries` represent folders under `galleries_root`. Nested folders become
subgalleries through `parent_id`. `images` represent image files directly inside
one gallery folder. Child folder images are intentionally not imported into the
parent gallery.

`cover_image_id` stores an editable title picture. If a gallery has no direct
cover image, public gallery cards can compose a small cover from child gallery
covers.

Protected-gallery access is stored on `galleries` separately from visibility.
`access_mode` determines whether public access is normal or protected,
`access_listing` determines whether a protected public gallery appears in public
listings, `access_password_hash` stores the optional gallery password hash, and
`access_token_hash`, encrypted `access_share_token`, and
`access_token_expires_at` manage admin-generated share links. Share-link
validation uses the hash; the encrypted token copy exists only so admins can see
and revoke the active URL later. Protected access is inherited from ancestors at
runtime. Password unlocks are session-scoped and expire after 10 minutes.

`tags`, `gallery_tags`, and `image_tags` store reusable tags. Admins edit tags as
comma-separated text; the UI recommends existing tags while typing. Public tag
links filter galleries by gallery tags and by image tags. Parent galleries can
also display `Containing tags`, which are aggregated from descendant galleries
and their images.

`image_votes` stores one vote per logged-in user or anonymous visitor hash.
Scores are summed from those rows, and the UI marks the current visitor's choice.

`picture_game_votes` stores pair history for the optional picture game. The pair
is normalized so the same two images cannot be repeated in reverse order for the
same viewer. A row is written when a pair is displayed, and `winner_image_id` is
filled when the viewer chooses a picture. The winning image also receives a
normal upvote in `image_votes`; the non-selected image receives no vote.

`gps_map_enabled` on `galleries` opts a branch into EXIF/GPS map support. The
setting is recursive, so a parent gallery enables maps for its descendants.
Image scans can populate `exif_taken_at`, camera metadata, and GPS coordinates
when the EXIF extension is available. `gps_lat`, `gps_lng`, `gps_altitude`, and
`gps_extracted_at` are stored separately from the source file and refreshed on
rescan. The migration also adds an index for gallery/GPS lookups.

`app_settings` stores configurable application values such as the public site
name, theme color overrides, radius override, font mode override, and selected
custom CSS preset. CSS files in `custom_css/` can be selected in the admin theme
screen; the selected file or a custom upload is copied to
`public/assets/custom.css` and loaded after the built-in stylesheet. The active
CSS skin supplies the default theme-control values. Once a control is changed,
`page=theme_css` loads after custom CSS and emits the saved overrides. The
Theme screen's `Reset to CSS` action removes those saved overrides without
removing the active custom CSS file.

`admin_logs` stores admin-visible operational events such as failed migration
runs and rejected admin-only actions. The dashboard renders recent entries, and
`page=admin_logs` provides the full workflow view with status filters and
bulk updates so admins can mark items as `todo`, `doing`, `waiting`, or `done`
without server log access.

The application updater is file-based so it can run on shared hosting without
Git. It reads the latest version from GitHub `PATCH_NOTES.md`, downloads a
branch zip, copies application-managed files, and backs up overwritten files
under `cache/updates/backups`. Local-only paths such as `config.php`,
`galleries/`, `cache/`, `custom_css/`, and `public/assets/custom.css` are
skipped.

The admin gallery tree collapse state is also stored in `app_settings` as a JSON
list of collapsed gallery IDs. The dashboard posts updates through
`page=admin_save_gallery_collapse`.

The public JavaScript intentionally detects inline `style` attributes and shows
a full-page warning. Theme changes should go through theme settings or custom
CSS, not ad hoc inline HTML styling.

When GPS maps are enabled, the gallery page renders a pin button on each image
with coordinates and a map button for the branch. The JavaScript loads Leaflet
on demand, opens an overlay with OpenStreetMap tiles, and uses a small JSON
endpoint to fetch all map points for the current gallery branch.

Migrations are ordered PHP files that return SQL statements. The runner records
applied versions in `schema_migrations`. The installer and migration runner
apply each migration file directly and do not open an explicit transaction
around the file. MySQL DDL statements may auto-commit schema changes, so the
application relies on statement ordering and the migration record table instead
of transaction wrapping.

Feature code that depends on a new migration should avoid fatal errors against
older databases. The picture game checks for its required column/table before
rendering admin controls and shows an authenticated `Run database migration`
prompt that posts to `page=admin_run_migrations`.

## Filesystem Rules

Gallery discovery starts at `galleries_root`. The app normalizes relative paths
and checks that image access stays inside the configured gallery folder. Public
media is served through `page=media`, not as raw filesystem paths.

Thumbnail generation creates a `thumbs/` directory inside each gallery folder.
Generated files are progressive JPEGs named from the original base filename plus
`_thumb300` or `_thumb800`, for example `photo_thumb300.jpg`. Discovery ignores
thumbnail folders, and scans only import direct source images from the gallery
folder. Public cards and image previews request the `800` thumbnail through
`page=thumb`; admin table previews use the `300` thumbnail. Missing thumbnails
fall back to the original media route until an admin generates them. The
lightbox intentionally uses the original protected media route instead of
thumbnails.

Thumbnail creation is incremental. If a generated file exists and is newer than
or equal to the source image, the service counts it as skipped and does not
rewrite it. Admin thumbnail forms progressively enhance to AJAX batches by
posting `ajax=1` to `page=admin_create_thumbnails`; the response reports total,
processed, created, skipped, and completion state for the progress UI.
Gallery import with `Create optimized thumbnails during import` checked uses an
AJAX import phase followed by the same thumbnail batch endpoint, so progress is
visible while thumbnails are created for newly imported galleries.

Each gallery can also have a `gallery.json` sidecar. The app writes metadata such
as title, description, visibility, sort order, and cover path there when gallery
metadata changes.

## Admin Workflow

1. Run `install.php` or manual setup.
2. Log in at `index.php?page=admin_login`.
3. Use `Check for new gallery folders`. The dashboard action refreshes already
   imported galleries for new or changed direct image files before it shows the
   folder discovery page, logs the refresh, and displays a wait indicator while
   the request is running.
4. Import selected folders. Selected parent folders automatically include
   detected descendant gallery folders.
5. Let import scan images and optionally create thumbnails, or run scan and
   thumbnail actions later from the dashboard/edit pages.
6. Filter the dashboard gallery table by visibility when a bulk gallery action
   should only target drafts, public galleries, or private galleries. The
   `Select displayed galleries` checkbox only selects rows that remain visible
   after both status filtering and collapsed tree branches.
7. Bulk-publish galleries or images.
8. Collapse or expand subgallery rows as needed; the state is persisted.
9. Edit titles, descriptions, tags, cover images, hierarchy, and theme settings.
10. Logged-in admins can also edit gallery and image titles/descriptions directly
   from public gallery pages through admin-only inline forms. Inline removal
   deletes CMS records only; filesystem folders and image files are left intact.
11. Opt galleries or gallery branches into the picture game from gallery edit
    pages or dashboard bulk actions. New galleries remain opted out by default.
12. Enable EXIF/GPS maps for gallery branches after running the `v_0.12`
    migration and rescanning existing images. The setting is recursive, so child
    galleries inherit map availability from enabled ancestors.
13. If a feature migration is pending, use the dashboard migration prompt to run
    it before enabling the related controls.

## Deployment

The project is designed for copy/FTP deployment. `deploy.bat` wraps the
PowerShell deploy script and can either upload by FTP or create a local
`deploy/` folder for manual upload. Deployment excludes local-only files such as
`.git`, `config.php`, caches, logs, and temporary output.

Production setup should create `config.php` on the target server. The browser
installer is the intended low-friction path because it can create the database,
run migrations, write config, and create the first admin user without console
access.

URL generation starts from `base_url` but corrects same-host HTTPS requests and
shared-hosting path mismatches against the current front-controller path. This
keeps generated CSS, JavaScript, media, and share-link URLs aligned with the
public URL even when a host exposes an internal folder such as `/subdom/name` as
the domain root.

## Security Notes

All database writes use PDO prepared statements. Admin POST routes require CSRF
tokens. Passwords use PHP password hashing. Public image access checks gallery
and image visibility unless an admin is logged in. Protected-gallery helpers are
centralized and used by public gallery pages, thumbnails, media, downloads, map
JSON, tags, votes, and the picture game.

`install.php` is a first-run endpoint. It refuses to run after either
`config.php` or `cache/installed.lock` exists, and successful browser installs
write both files. Deleting or server-blocking the installer after setup is still
reasonable defense in depth on public hosts.

The `app/`, `database/`, `scripts/`, `cache/`, and `galleries/` directories are
not intended to be browsed directly. Apache `.htaccess` files are included for
common hosting setups, but server-level configuration should enforce the same
rule where `.htaccess` is unavailable.
