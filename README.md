# PHP Gallery CMS

A small PHP 8+ gallery CMS for ordinary shared hosting. Media discovery comes from the filesystem, while gallery, image, vote, user, and ZIP cache state are stored in MySQL or MariaDB.

## Requirements

- PHP 8.0 or newer
- MySQL or MariaDB
- PHP extensions: PDO MySQL, ZipArchive, GD or another extension that supports `getimagesize`
- Apache with `.htaccess` support is recommended, but query-string routes also work

No WordPress, Composer packages, npm build step, or framework is required.

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

1. Open `install.php` and complete the browser-based setup, or create `config.php` manually from `config.example.php`.
2. If you use manual setup, create the database and run the setup URL with your `setup_key` or run the CLI scripts if your host provides shell access.
3. Ensure `galleries_root` and `zip_cache_path` are writable by PHP.
4. Delete or block `install.php` after setup.

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
