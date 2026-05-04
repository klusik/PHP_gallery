<?php

declare(strict_types=1);


// Load DB-backed application settings before any feature module reads app_setting().
require_once __DIR__ . '/services/app_settings.php';
// Load schema helpers before feature modules perform optional-column checks.
require_once __DIR__ . '/services/database_helpers.php';
// Load custom CSS helpers before theme rendering needs preset and asset paths.
require_once __DIR__ . '/services/custom_css.php';
// Load theme settings and CSS default helpers after custom CSS paths are available.
require_once __DIR__ . '/services/theme.php';
// Load favicon service helpers. Kept separate only after fixing module-relative paths.
require_once __DIR__ . '/services/favicon.php';
// Load gallery and theme background helpers after their module-relative paths were corrected.
require_once __DIR__ . '/services/gallery_backgrounds.php';
// Load reusable pagination helpers before controllers render public lists.
require_once __DIR__ . '/services/pagination.php';
// Load separated service modules. These require_once calls preserve the legacy app/services.php include contract.
require_once __DIR__ . '/services/gallery_mutations.php';
require_once __DIR__ . '/services/image_scanning.php';
require_once __DIR__ . '/services/uploads.php';
require_once __DIR__ . '/services/thumbnails.php';
require_once __DIR__ . '/services/gallery_covers.php';
require_once __DIR__ . '/services/gallery_access.php';
require_once __DIR__ . '/services/public_paths.php';
require_once __DIR__ . '/services/gallery_lookup.php';
require_once __DIR__ . '/services/gallery_sidecars.php';
require_once __DIR__ . '/services/gallery_paths.php';
require_once __DIR__ . '/services/gallery_display.php';
require_once __DIR__ . '/services/download_signatures.php';
require_once __DIR__ . '/services/downloads.php';
require_once __DIR__ . '/services/logs.php';
require_once __DIR__ . '/services/updates.php';
require_once __DIR__ . '/services/picture_game.php';
require_once __DIR__ . '/services/tags.php';
require_once __DIR__ . '/services/exif.php';
