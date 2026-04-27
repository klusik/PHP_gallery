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
- `page=tag&slug=...` renders a public gallery listing filtered by one tag.
- `page=media&id=...` streams an image through PHP after visibility checks.
- `page=thumb&id=...&size=...` streams a generated JPEG thumbnail after the
  same visibility checks.
- `page=admin` is the dashboard for discovery, scans, bulk actions, and edits.
- `page=admin_theme` stores theme controls and optional custom CSS.
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

`tags`, `gallery_tags`, and `image_tags` store reusable tags. Admins edit tags as
comma-separated text; the UI recommends existing tags while typing. Public tag
links filter galleries by gallery tags and by image tags. Parent galleries can
also display `Containing tags`, which are aggregated from descendant galleries
and their images.

`image_votes` stores one vote per logged-in user or anonymous visitor hash.
Scores are summed from those rows, and the UI marks the current visitor's choice.

`app_settings` stores theme values such as colors, radius, and font mode. A
custom CSS upload is saved to `public/assets/custom.css` and loaded after the
built-in stylesheet.

The admin gallery tree collapse state is also stored in `app_settings` as a JSON
list of collapsed gallery IDs. The dashboard posts updates through
`page=admin_save_gallery_collapse`.

The public JavaScript intentionally detects inline `style` attributes and shows
a full-page warning. Theme changes should go through theme settings or custom
CSS, not ad hoc inline HTML styling.

Migrations are ordered PHP files that return SQL statements. The runner records
applied versions in `schema_migrations`. MySQL DDL statements are not wrapped in
an explicit transaction because MySQL may auto-commit schema changes.

## Filesystem Rules

Gallery discovery starts at `galleries_root`. The app normalizes relative paths
and checks that image access stays inside the configured gallery folder. Public
media is served through `page=media`, not as raw filesystem paths.

Thumbnail generation creates a `thumbs/` directory inside each gallery folder.
Generated files are progressive JPEGs named from the original base filename plus
`_thumb300` or `_thumb800`, for example `photo_thumb300.jpg`. Discovery ignores
thumbnail folders, and scans only import direct source images from the gallery
folder. Public cards request responsive `300w` and `800w` thumbnail candidates
through `page=thumb` so the same visibility rules apply as original media.
Missing thumbnails fall back to the original media route until an admin
generates them. The lightbox intentionally uses the original protected media
route instead of thumbnails.

Thumbnail creation is incremental. If a generated file exists and is newer than
or equal to the source image, the service counts it as skipped and does not
rewrite it. Admin thumbnail forms progressively enhance to AJAX batches by
posting `ajax=1` to `page=admin_create_thumbnails`; the response reports total,
processed, created, skipped, and completion state for the progress UI.

Each gallery can also have a `gallery.json` sidecar. The app writes metadata such
as title, description, visibility, sort order, and cover path there when gallery
metadata changes.

## Admin Workflow

1. Run `install.php` or manual setup.
2. Log in at `index.php?page=admin_login`.
3. Use `Check for new gallery folders`.
4. Import selected folders. Selected parent folders automatically include
   detected descendant gallery folders.
5. Let import scan images and optionally create thumbnails, or run scan and
   thumbnail actions later from the dashboard/edit pages.
6. Bulk-publish galleries or images.
7. Collapse or expand subgallery rows as needed; the state is persisted.
8. Edit titles, tags, cover images, hierarchy, and theme settings.

## Deployment

The project is designed for copy/FTP deployment. `deploy.bat` wraps the
PowerShell deploy script and can either upload by FTP or create a local
`deploy/` folder for manual upload. Deployment excludes local-only files such as
`.git`, `config.php`, caches, logs, and temporary output.

Production setup should create `config.php` on the target server. The browser
installer is the intended low-friction path because it can create the database,
run migrations, write config, and create the first admin user without console
access.

## Security Notes

All database writes use PDO prepared statements. Admin POST routes require CSRF
tokens. Passwords use PHP password hashing. Public image access checks gallery
and image visibility unless an admin is logged in.

`install.php` should be deleted or blocked after setup because it can create or
modify database credentials.

The `app/`, `database/`, `scripts/`, `cache/`, and `galleries/` directories are
not intended to be browsed directly. Apache `.htaccess` files are included for
common hosting setups, but server-level configuration should enforce the same
rule where `.htaccess` is unavailable.
