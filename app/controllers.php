<?php

declare(strict_types=1);


// Load theme admin and theme asset routes after their service dependencies are available.
require_once __DIR__ . '/controllers/admin_theme.php';
require_once __DIR__ . '/controllers/theme_assets.php';
// Load separated controller modules. These require_once calls preserve the legacy app/controllers.php include contract.
require_once __DIR__ . '/controllers/http_helpers.php';
require_once __DIR__ . '/controllers/public_gallery.php';
require_once __DIR__ . '/controllers/public_media.php';
require_once __DIR__ . '/controllers/admin_auth.php';
require_once __DIR__ . '/controllers/admin_integrity.php';
require_once __DIR__ . '/controllers/admin_galleries.php';
require_once __DIR__ . '/controllers/admin_uploads.php';
require_once __DIR__ . '/controllers/admin_thumbnails.php';
require_once __DIR__ . '/controllers/admin_dashboard.php';
require_once __DIR__ . '/controllers/setup.php';
require_once __DIR__ . '/controllers/downloads.php';
require_once __DIR__ . '/controllers/admin_logs.php';
require_once __DIR__ . '/controllers/telemetry.php';
require_once __DIR__ . '/controllers/admin_telemetry.php';
require_once __DIR__ . '/controllers/updates.php';
require_once __DIR__ . '/controllers/picture_game.php';
require_once __DIR__ . '/controllers/tags.php';
require_once __DIR__ . '/controllers/exif.php';
