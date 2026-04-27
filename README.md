# PHP Gallery CMS

A small PHP 8+ gallery CMS for ordinary shared hosting. Media discovery comes from the filesystem, while gallery, image, vote, user, and ZIP cache state are stored in MySQL or MariaDB.

## Requirements

- PHP 8.0 or newer
- MySQL or MariaDB
- PHP extensions: PDO MySQL, ZipArchive, GD or another extension that supports `getimagesize`
- Apache with `.htaccess` support is recommended, but query-string routes also work

No WordPress, Composer packages, npm build step, or framework is required.

## Setup

1. Copy `config.example.php` to `config.php`.
2. Edit database credentials, `base_url`, `galleries_root`, `zip_cache_path`, `admin_session_name`, `visitor_vote_secret`, and `setup_key`.
3. Create the configured database in MySQL or MariaDB.
4. Run migrations and create an admin user.

From a shell:

```bash
php scripts/migrate.php
php scripts/create_admin.php admin "choose-a-strong-password"
```

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
5. Edit gallery title, description, slug, visibility, and sort order.
6. Edit image title, description, visibility, and sort order.

Discovery is explicit and does not run on public requests.

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

Public image voting posts to `index.php?page=vote` and returns JSON. Logged-in admins are associated by user ID. Anonymous visitors are associated by a SHA-256 hash of IP address, user agent, and `visitor_vote_secret`. Existing votes can be changed.

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

1. Create `config.php` on the server from `config.example.php`.
2. Create the database.
3. Run the setup URL with your `setup_key` or run the CLI scripts if your host provides shell access.
4. Ensure `galleries_root` and `zip_cache_path` are writable by PHP.

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
