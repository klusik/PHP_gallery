# PHP Gallery CMS

A small PHP 8+ gallery CMS for ordinary shared hosting. Media discovery comes from the filesystem, while gallery, image, vote, user, and ZIP cache state are stored in MySQL or MariaDB.

## Requirements

- PHP 8.0 or newer
- MySQL or MariaDB
- PHP extensions: PDO MySQL, ZipArchive, GD or another extension that supports `getimagesize`
- Apache with `.htaccess` support is recommended, but query-string routes also work

No WordPress, Composer packages, npm build step, or framework is required.

## Release Highlights

This release contains the full plain-PHP gallery application:

- browser installer through `install.php`
- filesystem gallery discovery with nested subgalleries
- admin dashboard with bulk gallery and image actions
- editable gallery metadata, cover images, tags, visibility, and hierarchy
- public gallery cards, breadcrumbs, lightbox browsing, and up/down voting
- theme controls and optional custom CSS upload
- ZIP downloads for one gallery or all galleries
- FTP/local deployment helper scripts
- Apache `.htaccess` files for routing and private-folder protection

## Setup

The easiest setup path is the browser installer. It is meant for ordinary shared
hosting, local Laragon/XAMPP-style installs, and cases where you do not want to
run console commands.

### Browser Installer

1. Upload or copy all project files to your webserver.
2. Open the installer in your browser:

```text
https://example.com/install.php
```

For a local Laragon example, that may look like:

```text
http://localhost/Galerie/install.php
```

3. Fill in the database administrator login. On local installs this is often
   user `root` with an empty password. On shared hosting, use the MySQL database
   credentials from your hosting control panel.
4. Choose the gallery database name and the database user/password the gallery
   application should use. The installer can create or update that user.
5. Leave `caching_sha2_password` selected for newer MySQL. If your host uses
   MariaDB or rejects that plugin, choose `Server default`.
6. Confirm the application paths. The installer will create the galleries folder
   and ZIP cache folder if PHP has permission.
7. Enter the first admin username and password.
8. Submit the form, then open:

```text
https://example.com/index.php?page=admin_login
```

The installer does these jobs for you:

- creates the database if it does not exist
- creates or updates the database user
- writes `config.php`
- creates the galleries and ZIP cache folders
- runs all database migrations
- creates the first admin account

After setup, delete `install.php` from the server or block access to it. Keeping
it online is unnecessary and should not be done on a public website.

If the installer says a folder is not writable, change the folder permissions in
your hosting control panel or create the folder manually. If database user
creation is not allowed by your host, create the database and user in the hosting
control panel first, then enter those credentials in the installer.

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
2. Use `Check for new gallery folders`.
3. Review detected folders and import selected galleries.
4. Use `Scan images` for each imported gallery.
5. Use bulk actions to scan, publish, draft, or privatize selected galleries.
6. Edit gallery title, description, slug, visibility, sort order, parent gallery,
   title picture, and tags.
7. Edit image title, description, visibility, sort order, and tags.

Discovery is explicit and does not run on public requests.

Nested folders become subgalleries. Public visitors get breadcrumb navigation,
subgallery cards, lightbox image browsing, and keyboard left/right navigation.

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

## Theme And Templates

Admins can open `index.php?page=admin_theme` to adjust the site look without
editing code. The current theme controls include:

- accent colors
- page and panel background colors
- corner roundness
- serif or sans-serif font mode
- optional custom CSS upload

Uploaded custom CSS is saved as `public/assets/custom.css` and loaded after the
built-in stylesheet. This gives technical users a template-like override path,
while non-technical users can use the form controls.

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
```

Do not rely on a CSS class as both a styling hook and a JavaScript behavior hook
unless there is no better option.

### UI Text

Use short, direct labels:

- `Save gallery`
- `Scan/import images`
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
index.php?page=admin
index.php?page=download_gallery&id=1
```

The root `index.php` delegates to `public/index.php`, so the project can work when either the repository root or `public/` is the web root.

## ZIP Downloads

Public gallery downloads include only public images from the selected gallery. Admin `Download all galleries` includes all imported images and preserves the original folder structure.

ZIP files are cached under `zip_cache_path`. The cache key is derived from image relative paths, sizes, and modification times. JPG, PNG, GIF, and WebP files are stored without extra compression when ZipArchive supports it.

## Voting

Public image voting posts to `index.php?page=vote` and returns JSON. Logged-in admins are associated by user ID. Anonymous visitors are associated by a SHA-256 hash of IP address, user agent, and `visitor_vote_secret`. Existing votes can be changed. The public UI marks the current visitor's selected up/down vote.

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
4. Delete or block `install.php` after setup.

The deployment helper intentionally does not upload `config.php`, cache files,
logs, Git metadata, or local development artifacts. Create production
configuration on the target server through `install.php` or by copying
`config.example.php` manually.

## Security Notes

- `config.php`, `app/`, `database/`, `scripts/`, and cache paths should not be publicly accessible.
- Media is served through `index.php?page=media&id=...`, not by raw folder path.
- Folder names can contain spaces and non-ASCII characters; public URLs use generated slugs.
- Only `jpg`, `jpeg`, `png`, `gif`, and `webp` files are imported.
- Image MIME data is validated with `getimagesize` during scans.
- Admin POST actions use CSRF tokens.
- SQL access uses PDO prepared statements.
- User and database output is escaped with `htmlspecialchars`.
- Passwords use `password_hash` and `password_verify`.
