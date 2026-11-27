# PHP Gallery CMS

**Your photographs. Your space.**

A self-hosted photo gallery for PHP hosting. Keep your originals in ordinary
folders, give collections their own look, and decide who can see them.

**[Download the latest release](https://github.com/klusik/PHP_gallery/releases/latest)**
· [Project website](https://phpgallery.site/)
· [Installation](#installation)
· [Windows uploader](#windows-uploader)
· [Manuals](#manuals)

**Current Version:** 1.8.2

[![PHP Gallery showing a collection of nested photo galleries](https://raw.githubusercontent.com/klusik/PHP_gallery/main/website/assets/real-gallery.jpg)](https://phpgallery.site/)

## What you can do

- **Organize** photographs in nested galleries, with search, tags and Smart Galleries.
- **Present** collections with fullscreen viewing, slideshows, music, PDFs and maps.
- **Share** public galleries, unlisted links or protected collections. Choose the
  appearance and access for each gallery.
- **Manage** uploads, covers, thumbnails and updates from the Admin workspace.

## Installation

Use PHP hosting with MySQL or MariaDB and the PDO MySQL, GD and ZipArchive
extensions. PHP 8.1 is the compatibility minimum. Choose a maintained PHP
release for deployment. [PHP support](docs/RUNTIME_SUPPORT.md) ·
[Database support](docs/DATABASE_SUPPORT.md)

1. Create an empty MySQL or MariaDB database in your hosting control panel.
2. Upload [setup-gallery.php](https://github.com/klusik/PHP_gallery/raw/main/setup-gallery.php)
   to the directory where you want to install PHP Gallery.
3. Open the uploaded file in your browser. Follow setup to connect the
   database and create your administrator account.

The [release downloads](https://github.com/klusik/PHP_gallery/releases/latest)
also include the full package for manual upload.

## Windows uploader

WinApp can watch a folder and upload new photographs to a selected gallery.
It also lets you browse galleries and import files from Windows.
The standard setup includes both desktop backends and their runtime.
Tkinter is the default. Switch to PySide6 in Settings and restart WinApp.
[Download WinApp](https://github.com/klusik/PHP_gallery/releases/latest) ·
[WinApp guide](winapp/README.md)

For development with CPython 3.14, install the complete source environment
with `python -m pip install -r winapp/requirements.txt`. The WinApp guide
covers [Tk-only setup, the Windows build and startup commands](winapp/README.md#dependency-installation).

## Manuals

[English](docs/PHP_Gallery_Manual.pdf) ·
[Česky](docs/PHP_Gallery_Manual_CZ.pdf) ·
[Deutsch](docs/PHP_Gallery_Manual_DE.pdf) ·
[Svenska](docs/PHP_Gallery_Manual_SV.pdf)

[Documentation on phpgallery.site](https://phpgallery.site/docs/) ·
[Patch notes](PATCH_NOTES.md) · [Gallery API](docs/API.MD)

## Help and development

[Discussions](https://github.com/klusik/PHP_gallery/discussions) ·
[Report an issue](https://github.com/klusik/PHP_gallery/issues/new/choose) ·
[Security](.github/SECURITY.md) · [Accessibility](ACCESSIBILITY.md)

[Contributing](CONTRIBUTING.md) · [Architecture](ARCHITECTURE.md) ·
[Testing](TESTING.md) · [License](LICENSE)
