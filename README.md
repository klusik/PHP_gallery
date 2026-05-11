# PHP Gallery CMS

A small PHP 8+ gallery CMS for ordinary shared hosting. Media discovery comes from the filesystem, while gallery, image, vote, user, and ZIP cache state are stored in MySQL or MariaDB.

## Recommended Installation: One-File Web Installer

For normal shared hosting, the easiest installation is the new one-file bootstrap installer.
You upload one PHP file by FTP, open it in the browser, and it downloads the full gallery package from GitHub directly on the server.

### Fast Shared-Hosting Install

1. Create an empty MySQL or MariaDB database in your hosting control panel.
2. Upload only this file to the target web directory:

```text
setup-gallery.php
```

3. Open it in your browser:

```text
https://example.com/setup-gallery.php
```

4. Click `Download and start installer`.
5. The bootstrap installer downloads the gallery archive from GitHub, unpacks it into the current directory, creates `cache/bootstrap-installed.lock`, and redirects to:

```text
https://example.com/install.php
```

6. Continue through the normal browser installer:
   - enter the gallery name
   - enter the existing database server, database name, database user, and password
   - create the first gallery admin account
   - finish the installation

The bootstrap installer is intentionally small. It only delivers the application files onto the server. Database configuration, admin creation, migrations, folders, and the final installation lock are handled by `install.php`.

### Bootstrap Installer Safety Rules

`setup-gallery.php` refuses to run when the gallery already appears to be installed.
It stops when any of these files exist:

```text
config.php
cache/installed.lock
cache/bootstrap-installed.lock
```

This prevents accidental overwrites after the site is configured.
The script is not deleted automatically, because some webhostings make file deletion unreliable from PHP. After a successful installation, you can delete `setup-gallery.php` manually by FTP as an extra hardening step.

### Bootstrap Installer Requirements

The one-file installer needs:

- PHP 8.0 or newer
- writable target web directory
- PHP `ZipArchive`
- outbound HTTPS access to GitHub through cURL or `allow_url_fopen`

If the hosting provider disables outbound downloads or ZIP extraction, use the experienced-user install below and upload the full package manually.

## Requirements

- PHP 8.0 or newer
- MySQL or MariaDB
- PHP extensions: PDO MySQL, ZipArchive, and GD for thumbnail creation
- Apache with `.htaccess` support is recommended, but query-string routes also work

No WordPress, Composer packages, npm build step, or framework is required.

## Experienced-User Installation

Use this path if you prefer uploading the complete package yourself, you are installing locally, or your hosting environment prevents the one-file bootstrap installer from running.

### Browser Installer After Full Upload

1. Upload or copy all project files to your webserver.
2. Open the site in your browser. The application automatically redirects the
   first request to the installer while `config.php` does not exist:

```text
https://example.com/
```

For a local Laragon example, that may look like:

```text
http://localhost/Galerie/
```

You can also open `install.php` directly, but it is not required.
3. Choose the database provisioning mode:
   - On shared hosting, keep `Use existing database and existing database user`.
     Create the database and user in the hosting control panel first, then enter
     those credentials in the installer.
   - On local installs, choose `Create database and database user` if you want
     the installer to provision them with a database administrator account such
     as `root`.
4. Choose the gallery database name and the database user/password the gallery
   application should use.
5. Authentication plugin is only used in create mode. Leave it on `Server default`
   for MariaDB and most shared hosts. Use `caching_sha2_password` only when you
   know the target MySQL server requires it.
6. Confirm the application paths. The installer will create the galleries folder
   and ZIP cache folder if PHP has permission.
7. Enter the first admin username and password.
8. Submit the form. The installer then:
   - writes `config.php`
   - creates the galleries and ZIP cache folders
   - runs all database migrations without wrapping each migration file in an explicit transaction
   - creates the first admin account
   - writes `cache/installed.lock`

After setup, open the admin login page:

```text
https://example.com/index.php?page=admin_login
```

If the installer says a folder is not writable, change the folder permissions in
your hosting control panel or create the folder manually. If database user
creation is not allowed by your host, create the database and user in the hosting
control panel first, then enter those credentials in the installer.

The installer does these jobs for you:

- creates the database if it does not exist
- creates or updates the database user
- writes `config.php`
- creates the galleries and ZIP cache folders
- runs all database migrations without wrapping each migration file in an explicit transaction
- creates the first admin account
- writes `cache/installed.lock`

After setup, the installer disables itself automatically. Future requests to
`install.php` are rejected when either `config.php` or `cache/installed.lock`
exists. You can still delete or server-block `install.php` as an extra hardening
step on public websites.

### Manual Setup

Use this path only if you prefer shell commands or your hosting environment does
not allow the browser installer to create the database/user.

1. Copy `config.example.php` to `config.php`.
2. Edit database credentials, `base_url`, `galleries_root`, `zip_cache_path`, `admin_session_name`, `visitor_vote_secret`, and `setup_key`.
3. Create the configured database in MySQL or MariaDB.
4. Run migrations and create an admin user.

From a shell:

```bash
php scripts/migrate.php
php scripts/create_admin.php admin "choose-a-strong-password"
```

If MySQL reports `Plugin 'mysql_native_password' is not loaded`, recreate or alter the
database user to use the server's current authentication plugin. On MySQL 8/9 this is
usually `caching_sha2_password`:

```sql
ALTER USER 'gallery_user'@'localhost' IDENTIFIED WITH caching_sha2_password BY 'change-me';
ALTER USER 'gallery_user'@'127.0.0.1' IDENTIFIED WITH caching_sha2_password BY 'change-me';
FLUSH PRIVILEGES;
```

Use the same password in `config.php`. If the user does not exist for one of those
hosts, create it and grant access to the gallery database instead.

On shared hosting without shell access, visit:

```text
https://example.com/index.php?page=setup&key=YOUR_SETUP_KEY
```

That page runs pending migrations and lets you create or update the admin user. Change or remove `setup_key` after setup.

After the application is installed, logged-in admins can also run pending
migrations from the dashboard when a new feature needs a database change. The
admin page shows a migration notice with a `Run database migration` button when
the current database schema is too old for the visible feature controls. The
same migration runner applies statements directly rather than opening a manual
transaction around each migration file.

The admin dashboard also shows a small database-backed admin log with recent
maintenance events and failures so gallery admins can inspect what happened
without server log access.

Use `Admin dashboard -> View log` to open the full workflow screen. From there,
admins can filter events and mark them as `To be done`, `Will be done`,
`Waiting`, or `Done`.

Logged-in admins can use `Admin dashboard -> Updates` to check GitHub for newer
versions. The updater reads `CMS_VERSION` from `app/bootstrap.php` as the
source of truth, downloads the configured GitHub branch archive, backs up
overwritten files under `cache/updates/backups`, and leaves local `config.php`,
galleries, cache files, and active custom CSS untouched. One-button
installation requires outbound HTTPS from the server and PHP `ZipArchive`. When
a newer version is available, the admin Updates button shows `Update(1)` with a
fixed warning style that does not use the selected theme colors.

The Updates page also includes a collapsible patch notes viewer. It can fetch
`PATCH_NOTES.md` from the configured GitHub branch, cache parsed release notes
under `cache/updates/patch-notes`, fall back to the bundled local notes when
GitHub is unavailable, and switch versions dynamically without reloading the
whole admin page.


## Admin Account And Password Recovery

Admin accounts can be managed from `Admin dashboard -> Profile` or directly at
`index.php?page=admin_account`. The account page controls the username,
optional recovery email, password changes, and password reset email delivery.
Changing identity fields or the password requires the current password.

Password reset delivery is optional and disabled until configured. The account
page supports:

- PHP `mail()` transport
- SMTP transport
- STARTTLS, implicit TLS / SSL, or no SMTP encryption
- sender email and sender name settings
- reset link lifetime from 15 to 1440 minutes
- a test email that does not create a reset token

The public login flow has `Forgot password`, reset-link creation, and reset-link
completion pages. Reset links require the password reset token schema, an admin
account recovery email, enabled delivery settings, and a configured sender
address.

Login and reset requests are rate-limited after the `auth_rate_limits` migration
has run. The throttling service stores hashed subjects only. Raw IP addresses
and raw submitted usernames or email addresses are not stored in the throttle
table.

## Local Run

Use PHP's built-in server from the repository root:

```bash
php -S localhost:8000 index.php
```

Then open `http://localhost:8000/index.php`. If you use `public/` as the web root:

```bash
php -S localhost:8000 -t public public/index.php
```

## Gallery Workflow

Place media folders below the configured galleries root:

```text
galleries/
  A gallery about flying/
    IMG_001.jpg
    IMG_002.jpg
    gallery.json
  Trains/
    2026 Prague/
      IMG_010.jpg
      gallery.json
```

Admin workflow:

1. Log in at `index.php?page=admin_login`.
2. Use `Check for new gallery folders`. This also scans already-imported
   galleries for newly added or changed direct image files.
3. Review detected folders and import selected galleries. Importing a parent
   folder also imports its detected subgallery folders.
4. Leave `Create optimized thumbnails during import` checked unless you want to
   generate thumbnails later from the admin dashboard. With JavaScript enabled,
   import thumbnail creation shows a progress bar with processed, created, and
   skipped counts.
5. Filter the admin gallery table by status when you need a focused bulk
   change. `Select displayed galleries` only selects rows currently visible
   after filtering and tree collapsing, so bulk actions can safely target
   only drafts, only public galleries, or only private galleries.
6. Use `Create empty gallery` when you want to create a real empty folder before
   adding photos later through FTP or the upload page.
7. Use `Upload photos` to upload multiple images into an existing gallery or to
   create a new gallery directly from an upload. With JavaScript enabled, the
   upload shows transfer progress and then thumbnail progress when optimized
   thumbnails are requested.
8. Use bulk actions to scan, publish, draft, or privatize selected galleries.

9. Use the gallery or image thumbnail buttons when you need to rebuild generated
   thumbnails after replacing source files.
10. Edit gallery title, description, optional manual gallery date, slug, folder
    name, visibility, sort order, parent gallery, title picture, description
    layout, and tags. Changing the folder name or parent gallery moves the real
    folder subtree on disk and updates database paths for all descendants.
11. Edit image title, description, visibility, sort order, and tags.
12. When logged in, use compact public-page admin actions to open the side-panel
    editor for galleries and photos. These actions can edit metadata, upload
    photos, create child galleries, publish, hide, or remove CMS records without
    navigating away from the public gallery view. Removing a record does not
    delete the underlying folder or image file from disk.
13. Opt selected galleries or whole gallery branches into the picture game when
    you want visitors to compare images side by side.
14. Enable EXIF GPS maps on gallery branches where you want public photo pins
    and gallery map overlays.
15. Use protected access controls on a gallery edit page when a public gallery
    should require a password, disappear from listings, or be opened only by a
    share link.

Discovery is explicit and does not run on public requests.

Nested folders become subgalleries. Public visitors get breadcrumb navigation,
subgallery cards, lightbox image browsing, keyboard left/right navigation, and
visible keyboard up/down voting controls in the lightbox.


## Gallery Descriptions, Dates, And Card Layouts

Gallery descriptions support a small safe Markdown subset for public display.
The editor shows formatting hints for common syntax such as bold text, italic
text, inline code, and links. Single line breaks are preserved, and empty lines
create separate paragraphs.

Galleries can also store an optional manual date. This is not the upload date
and is not derived from image metadata. It is intended for an event date, trip
date, shooting date, or any other admin-chosen gallery date. Existing galleries
keep the value empty after migration and do not display a placeholder.

Manual gallery dates are available in:

- the create gallery page
- the create-and-upload workflow
- the side-panel gallery creation UI
- the gallery editor identity section
- public gallery hero metadata
- public gallery cards, when a date is set

The date value is stored in the `galleries.gallery_date` column and can also be
written to `gallery.json` sidecar metadata. Invalid dates are rejected before
saving.

Public subgallery cards support two description layout systems:

- `Vertical system`: the existing image-left card layout
- `Horizontal system`: image on top, then title, date, tags, and a shortened
  Markdown-capable description

The default card layout is configured in Theme settings and applies to all
galleries. Individual galleries can override the Theme default from the gallery
editor. A gallery with no override inherits the Theme default.

## Public SEO

Public gallery pages follow the filesystem-first model and emit SEO metadata
without adding a separate content store.

- Public gallery cards link to the clean `/gallery/{slug}/` route.
- Other gallery navigation, redirects, forms, and admin links keep using the
  stable query-string route for now.
- Canonical gallery URLs use the clean `/gallery/{slug}/` route, while the
  original query-string route continues to work.
- Nested filesystem paths such as `/gallery/travel/italy/rome/` are accepted as
  compatibility routes when rewrite rules are enabled, but generated public
  links and canonical tags use the unique slug route.
- Page titles and descriptions resolve in this order:
  1. `gallery.json`
  2. database values
  3. folder name for the title, gallery title for the description fallback
- `gallery.json` supports `title`, `description`, and `tags`. Tags may be a
  comma-separated string or a JSON array.
- Each public gallery page emits one `<h1>` that matches the gallery title.
- Image `alt` text resolves from caption metadata, then filename, then a
  gallery-based fallback.
- `robots.txt` allows public crawling, disallows admin routes, and advertises the
  sitemap.
- `sitemap.xml` lists public, non-protected galleries with absolute URLs.
- Public gallery pages also emit Open Graph, Twitter card, and JSON-LD data.

## Protected Galleries

Gallery visibility and access are separate controls. `public` galleries can use
normal public access, password-protected access, or share-link-only access.
Draft and private galleries remain admin-only.

Password-protected galleries can stay listed publicly, but their public cards do
not show thumbnails or descendant cover collages. Visitors enter the configured
gallery password to unlock the gallery branch for 10 minutes. Protection is
inherited by subgalleries, so a protected parent also protects its descendants.

Share-link-only galleries are unlisted by default and are reachable only through
the generated share URL, such as:

```text
index.php?page=share&id=123&token=...
```

Admins can generate, regenerate, expire, and revoke share links from the gallery
edit page. Current share links remain visible there after the persistent share
link migration has run; the admin-display copy is encrypted at rest with the
local application secret, while link validation still uses only a token hash.
Older hash-only links can still be revoked or replaced, but their original token
cannot be displayed.

## Picture Game

The picture game is optional and opt-in. New galleries are excluded by default.
Admins can enable it from a gallery edit page or with the dashboard bulk action.
Bulk enabling or disabling applies to the selected galleries and their
subgalleries.

If the picture game migration has not been applied yet, the dashboard hides the
picture-game bulk controls and shows an admin-only migration prompt. Use the
`Run database migration` button there, or run `php scripts/migrate.php` from a
shell, before enabling the game.

When a public gallery branch has at least two eligible public images, the gallery
page shows a `Play picture game` button. The game displays two pictures side by
side at the same height. Visitors choose the image they prefer by clicking it or
using the left/right arrow keys. The selected image receives one normal upvote;
the other image receives no vote and is not downvoted.

Each viewer has pair history based on the same visitor identity used for public
voting. A picture pair is recorded as soon as it is displayed, so the same pair
is not shown again to that viewer. When no unseen pairs remain, the game shows a
completion message. The game page also shows global top-picture statistics for
the current gallery game.

## EXIF / GPS Maps

If your PHP installation has the EXIF extension enabled, image scans can store
safe camera and GPS metadata for JPEG and TIFF files. GPS coordinates are kept
in the database and refreshed when the image is scanned again.

Admins can opt a gallery branch into GPS maps from the gallery edit page or
with the dashboard bulk action. The setting applies recursively to subgalleries,
so child galleries inherit map availability from any enabled ancestor.

When enabled, public image cards can show a map pin for photos with GPS
coordinates, the lightbox can open a single-photo map, and gallery pages can
open a combined map for all GPS-enabled public photos in the current branch.

Use the map controls only after running the `v_0.12` migration and rescanning
the galleries so existing images populate the new EXIF/GPS fields.

The map overlay uses Leaflet with OpenStreetMap tiles. That works without a paid
Google Maps API key, but the default public tile service is intended for light
usage and testing rather than heavy production traffic.

## Thumbnails

Thumbnail generation creates a `thumbs/` folder inside each imported gallery
folder when needed. Every generated file is a JPEG, even when the source image is
PNG, GIF, or WebP. The filenames keep the original base name and add the target
size:

```text
some_picture.jpg
thumbs/some_picture_thumb300.jpg
thumbs/some_picture_thumb600.jpg
thumbs/some_picture_thumb800.jpg
```

Public gallery cards and image previews use the `800` variant so title pictures
do not fall back to small admin thumbnails. Admin table previews use the smaller
`300` variant. Both sizes mean "maximum longer side"; smaller source images are
not enlarged. The public lightbox loads the original protected media route and
links the displayed image to that same original route.

Admins can create or rebuild thumbnails in several places:

- during import, with the default-checked import option
- from the dashboard, for all galleries
- from a gallery row, for that gallery
- from a gallery edit page, for all images or selected images

Generated thumbnails are served through `index.php?page=thumb&id=...&size=...`
so gallery and image visibility checks still apply.

Existing thumbnails are not regenerated when they are already newer than the
source image. Dashboard and import thumbnail actions run in AJAX batches when
JavaScript is enabled, showing a progress bar with checked images plus created
and skipped file counts. Gallery row and edit-page thumbnail buttons still
submit normally without JavaScript.

The dashboard uses a cached thumbnail maintenance summary when possible and can
defer expensive thumbnail scans during the first admin render. Use the dedicated
thumbnail actions when an exact repair or full rebuild is needed.

## Tags

Admins can tag galleries and individual images from their edit pages. Tags are
entered as comma-separated text. Existing tags are suggested while typing so the
same tag names can be reused consistently.

Tags are stored in reusable database tables and displayed on public gallery and
image cards. Public tags are clickable. Clicking a tag opens a filtered gallery
listing such as:

```text
index.php?page=tag&slug=travel
```

Parent galleries also show `Containing tags` when their subgalleries or
subgallery images use tags. This makes top-level galleries useful even when the
top-level folder only contains subfolders.

## Theme And Templates

Admins can open `index.php?page=admin_theme` to adjust the site look without
editing code. The current theme controls include:

- site name
- accent colors
- page, panel, and open-gallery panel background colors
- corner roundness
- serif or sans-serif font mode
- selectable custom CSS skins from `custom_css/`
- optional custom CSS upload
- default public gallery-card description layout
- admin/public language settings and language coverage diagnostics

Uploaded custom CSS is saved as `public/assets/custom.css` and loaded after the
built-in stylesheet. The form controls read their defaults from the active CSS
skin; once a color, radius, or font control is changed and saved, the generated
theme stylesheet loads after custom CSS and overrides the core gallery colors.
Use `Reset to CSS` on the Theme screen to clear saved slider overrides and return
the controls to the active CSS defaults.

The `custom_css/` folder contains selectable skins and examples. Use
`custom_css/css_template.css` as a commented starting point, choose
`custom_css/custom.css` for a compact admin-oriented style, or select
`custom_css/modern.css` for a cleaner modern gallery look.

The site name in the header and browser title is also managed from the Theme
screen, so the default `Gallery CMS` label can be replaced without editing code.

Theme settings also control the default public subgallery description layout.
Use the vertical system for the existing image-left presentation, or the
horizontal system when gallery cards should show the image on top followed by
title, date, tags, and a compact description.

The Language area lets admins select separate languages for the public zone and
admin zone. Translation strings are loaded from language files with English
fallbacks, and the language coverage table helps identify missing translations.

## Naming And Design Conventions

This project is intentionally small and direct. New features should keep names
boring, predictable, and easy to search.

### Routes And Pages

Routes use the `page` query parameter and snake_case names:

```text
index.php?page=admin_edit_gallery&id=1
index.php?page=admin_theme
index.php?page=download_gallery&id=1
```

Route names should follow these rules:

- public pages use the noun or action directly: `home`, `gallery`, `media`,
  `vote`
- admin pages start with `admin_`: `admin_edit_image`, `admin_bulk_images`
- destructive or state-changing routes must be POST-only and CSRF-protected
- admin-only maintenance routes, such as `admin_run_migrations`, must also be
  POST-only and CSRF-protected
- admin-only operational logs should be stored in the database and shown only
  to logged-in admins
- route handler functions use the same name with the `cms_` prefix:
  `cms_admin_edit_gallery`

### PHP Functions

PHP functions use snake_case. Use prefixes to show responsibility:

- `cms_*` for route/controller functions
- `render_*` for HTML rendering helpers
- `find_*` for single database lookups
- `*_options` for HTML `<option>` builders
- `*_url` for URL helpers
- `sync_*` for replace/update operations that reconcile stored state

Keep controller functions focused on request handling and HTML. Put reusable
database, filesystem, ZIP, tag, vote, cover, and theme behavior in
`app/services.php`.

### Database Names

Database tables and columns use snake_case:

```text
galleries
image_votes
cover_image_id
visitor_hash
created_at
updated_at
```

Use singular IDs like `gallery_id`, `image_id`, and `tag_id`. Many-to-many join
tables use both nouns in plural form, such as `gallery_tags` and `image_tags`.

Every table that stores editable records should have `created_at` and
`updated_at`. Join tables do not need timestamps unless they later gain their
own metadata.

### CSS Classes

CSS classes use kebab-case:

```css
.gallery-card
.image-card
.vote-row
.tag-list
.site-header
```

Class names should describe the element's role, not its current color or exact
position. Prefer `.gallery-card` over `.brown-box`, and `.bulk-row` over
`.top-left-controls`.

State classes use the `is-` prefix:

```css
.is-active
.is-subgallery
```

JavaScript hooks use `data-*` attributes instead of styling classes:

```html
data-lightbox-image
data-vote-form
data-tag-input
data-gallery-visibility-filter
data-gallery-row
data-gallery-upload-form
```

Do not rely on a CSS class as both a styling hook and a JavaScript behavior hook
unless there is no better option.

### UI Text

Use short, direct labels:

- `Save gallery`
- `Scan/import images`
- `Select displayed galleries`
- `Upload photos`
- `Set public`
- `Title picture`
- `Check for new gallery folders`

Avoid clever or decorative wording. Admin screens should feel practical and
repeatable. Public pages should be clear and calm.

### Visual Design

The default UI should remain a quiet gallery CMS, not a marketing landing page.
Use these rules when adding or changing screens:

- keep content centered in the existing `1120px` layout
- use `.panel` for admin/edit surfaces
- use `.grid` for repeated gallery or image cards
- use `.gallery-card` only for galleries
- use `.image-card` only for images
- keep cards readable on mobile and desktop
- preserve theme variables such as `--accent`, `--panel`, `--paper`, and
  `--radius`
- do not hardcode new dominant colors unless they are derived from the theme
- keep buttons visually consistent with the existing rounded button style
- show current state clearly, for example active votes use `.is-active`
- keep public image browsing in the lightbox, with visible previous/next buttons
  and keyboard left/right support
- keep the lightbox vote controls visible; keyboard up/down maps to vote up/down
- never add inline `style=""` attributes to rendered HTML; the public JavaScript
  treats inline style attributes as tampering and shows a full-page warning

Gallery title pictures should be compact. If a parent gallery has no direct
image, its card may use a small collage from subgallery covers, but it should
still occupy the same visual space as a normal title picture.

### Forms

Forms should use clear labels and existing form patterns:

- use `class="form-grid"` for stacked edit forms
- use `class="bulk-row"` for bulk action controls above tables
- use `<select>` for fixed choices such as visibility or parent gallery
- use `<input type="color">` for theme colors
- use comma-separated text for tags, with `data-tag-input` and the shared tag
  datalist

Any admin POST form must include `csrf_field()` and the target controller must
call `verify_csrf()`.

### Documentation

When adding a feature, update the relevant README section and, if the feature
changes the architecture or data flow, update `ARCHITECTURE.md`.

Add code comments only where they explain a non-obvious decision, such as why
MySQL DDL migrations are not wrapped in explicit transactions. Avoid comments
that simply repeat the next line of code.

## Routing

Pretty URLs are attempted through `.htaccess`, but every route also works by query string:

```text
index.php?page=home
index.php?page=gallery&slug=my-gallery
index.php?page=share&id=1&token=...
index.php?page=admin
index.php?page=download_gallery&id=1
```

The root `index.php` delegates to `public/index.php`, so the project can work when either the repository root or `public/` is the web root.

## ZIP Downloads

Public gallery downloads include only public images from the selected gallery. Admin `Download all galleries` includes all imported images and preserves the original folder structure.

ZIP files are cached under `zip_cache_path`. The cache key is derived from image relative paths, sizes, and modification times. Cached ZIP files are reused for up to 7 days. Expired cache entries are removed and regenerated automatically when a gallery ZIP or the admin all-galleries ZIP is requested. JPG, PNG, GIF, and WebP files are stored without extra compression when ZipArchive supports it.

## Voting

Public image voting posts to `index.php?page=vote` and returns JSON. Logged-in admins are associated by user ID. Anonymous visitors are associated by a SHA-256 hash of IP address, user agent, and `visitor_vote_secret`. Existing votes can be changed. The public UI marks the current visitor's selected up/down vote.

## Admin Dashboard Diagnostics

When dev mode or admin diagnostics are enabled, the dashboard can show an
admin-only render profile with counters and timers for schema checks, database
queries, setting reads, gallery preview lookup, thumbnail maintenance summary
reads, gallery ordering, and row rendering. This is intended for development and
performance tuning only and is not visible to anonymous visitors.

## Admin Gallery Tree

The admin gallery table is hierarchical. Subgalleries can be collapsed or
expanded individually, and the dashboard has `Collapse all` and `Expand all`
controls. The collapsed gallery IDs are stored in `app_settings`, so the tree
state persists across reloads.

## Filesystem-First Galleries

Gallery folders on disk are the source of truth for hierarchy. Database rows
store the index, slugs, visibility, tags, protection settings, and other
metadata, but `folder_path` is expected to match a real folder under
`galleries_root`.

Admin folder operations preserve that rule. Creating an empty gallery creates an
empty folder and a `gallery.json` sidecar. Uploading photos writes image files
into the selected gallery folder, then scans that folder so uploaded and
FTP-added images are represented the same way. Changing a gallery folder name or
parent moves the folder subtree on disk first, then updates the affected
database `folder_path` values.

## FTP Deployment

Run:

```bat
deploy.bat
```

The script prompts for FTP host, username, password, remote folder, and whether media should be uploaded. It excludes `.git`, `config.php`, cache, logs, temporary files, and local development artifacts.

If you do not want the script to upload by FTP, choose local deploy folder mode when prompted. It creates a `deploy/` directory containing the files that should be copied manually with your FTP client.

You can also run either mode explicitly:

```bat
deploy.bat -Mode local
deploy.bat -Mode ftp
```

After upload:

1. Open `install.php` and complete the browser-based setup, or create `config.php` manually from `config.example.php`.
2. If you use manual setup, create the database and run the setup URL with your `setup_key` or run the CLI scripts if your host provides shell access.
3. Ensure `galleries_root` and `zip_cache_path` are writable by PHP.
4. Optionally delete or block `install.php` after setup. The script also refuses
   to run once `config.php` or `cache/installed.lock` exists.

The deployment helper intentionally does not upload `config.php`, cache files,
logs, Git metadata, or local development artifacts. Create production
configuration on the target server through `install.php` or by copying
`config.example.php` manually.

## Security Notes

- `config.php`, `app/`, `database/`, `scripts/`, `tests/`, `deploy/`, cache paths, logs, temporary files, and local development artifacts should not be publicly accessible.
- Apache installs use `Options -Indexes` and deny common sensitive file extensions such as `.md`, `.sql`, `.zip`, `.log`, `.env`, `.yml`, `.yaml`, `.lock`, backups, and old/original copies.
- Dot-directories and dotfiles are blocked except the standard `.well-known/` path.
- Media is served through `index.php?page=media&id=...`, not by raw folder path.
- Protected gallery checks also apply to thumbnails, media, downloads, maps,
  votes, tags, and the picture game.
- Folder names can contain spaces and non-ASCII characters; public URLs use generated slugs.
- Only `jpg`, `jpeg`, `png`, `gif`, and `webp` files are imported.
- Image MIME data is validated with `getimagesize` during scans and uploads.
- Admin uploads are stored inside `galleries_root` with collision-safe filenames.
- Admin POST actions use CSRF tokens.
- Admin login and password reset requests are rate-limited after the `auth_rate_limits` migration has run.
- SQL access uses PDO prepared statements.
- User and database output is escaped with `htmlspecialchars`.
- Passwords use `password_hash` and `password_verify`.
