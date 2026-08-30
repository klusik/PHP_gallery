<?php

/**
 * Focused regression checks for version 0.94 audit hardening.
 *
 * This CLI test intentionally avoids database access and destructive application
 * operations. Real HTTP error/gate fixtures are exercised separately with the
 * PHP built-in server or Apache by the release validation commands.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];

$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

require $root . '/app/early_runtime.php';
require $root . '/app/helpers_public_urls.php';

$image = [
    'id' => 995,
    'checksum_sha256' => str_repeat('a', 64),
    'modified_at' => '2026-08-30 18:00:00',
    'file_size' => 10074279,
    'relative_path_hash' => str_repeat('b', 64),
    'thumbnail_derivative_version' => 4,
];
$versionA = \Gallery\Core\image_public_asset_version($image);
$versionB = \Gallery\Core\image_public_asset_version($image);
$assert($versionA !== '', 'Media cache identity must not be empty.');
$assert(hash_equals($versionA, $versionB), 'Unchanged media must produce a stable cache identity.');
$url = \Gallery\Core\image_public_asset_url_with_version('index.php?page=media&id=995', $image);
$assert(str_contains($url, '&v=' . $versionA), 'Legacy query media URL must carry the stable version parameter.');
$changed = $image;
$changed['checksum_sha256'] = str_repeat('c', 64);
$assert(!hash_equals($versionA, \Gallery\Core\image_public_asset_version($changed)), 'Changed source identity must change the media cache identity.');

$seoGuard = file_get_contents($root . '/app/services/seo_request_guard.php') ?: '';
foreach (["'media' => ['id', 'v']", "'public_media' => ['public_path', 'v']"] as $expected) {
    $assert(str_contains($seoGuard, $expected), 'SEO guard must accept versioned media query parameter: ' . $expected);
}

$canonicalCallSites = [
    'app/controllers/gallery_lightbox.php' => 'image_public_media_url($image, $gallery)',
    'app/controllers/public_gallery_lightbox.php' => 'image_public_media_url($image, $gallery)',
    'app/controllers/public_gallery_page.php' => 'image_public_media_url($image, $gallery)',
    'app/controllers/smart_galleries.php' => 'image_public_media_url($image, $source)',
];
foreach ($canonicalCallSites as $relative => $expectedCall) {
    $source = file_get_contents($root . '/' . $relative) ?: '';
    $assert(!str_contains($source, "url_for('media', ['id' => \$image['id']])"), $relative . ' must not emit an unversioned legacy media URL.');
    $assert(str_contains($source, $expectedCall), $relative . ' must use the canonical public media helper with the physical gallery context.');
}

foreach ([
    'app/services/public_gallery_media_manifest.php',
    'app/services/thumbnail_bundles.php',
] as $relative) {
    $source = file_get_contents($root . '/' . $relative) ?: '';
    $assert(str_contains($source, 'image_public_asset_url_with_version'), $relative . ' defensive media fallback must remain versioned.');
}

$mediaController = file_get_contents($root . '/app/controllers/public_media.php') ?: '';
$assert(str_contains($mediaController, "public_media_needs_private_cache(\$gallery, \$image)"), 'Media authorization-sensitive cache policy must still use public_media_needs_private_cache().');
$assert(str_contains($mediaController, "'public, max-age=31536000, immutable'"), 'Public media immutable cache policy must remain enabled.');
$assert(str_contains($mediaController, "'private, max-age=300'"), 'Protected media must retain private cache policy.');
$assert(str_contains($mediaController, 'send_conditional_file_headers($path, $cacheControl)'), 'Media ETag/conditional response path must remain intact.');

$htaccess = file_get_contents($root . '/.htaccess') ?: '';
$assert(str_contains($htaccess, 'RewriteRule ^(?:app|database|scripts|cache|data|logs|tmp|tests|deploy)(?:/|$)'), 'Root .htaccess must protect internal top-level trees in per-directory context.');
$assert(!str_contains($htaccess, 'RedirectMatch 404 ^/(app|database|scripts|cache|logs|tmp|tests|deploy)'), 'Prefix-sensitive protected-directory RedirectMatch must be removed.');
$assert(str_contains($htaccess, 'RedirectMatch 404 (^|/)\.(?!well-known/)'), '.well-known exception and dotfile protection must remain present.');
$publicHtaccess = file_get_contents($root . '/public/.htaccess') ?: '';
$assert(str_contains($publicHtaccess, 'RewriteRule ^(?:app|database|scripts|cache|data|logs|tmp|tests|deploy)(?:/|$)'), 'Public-document-root .htaccess must retain defense-in-depth protection for internal top-level names.');

$publicEntrypoint = file_get_contents($root . '/public/index.php') ?: '';
$assert(str_contains($publicEntrypoint, "require_once __DIR__ . '/../app/early_runtime.php'"), 'Public front controller must load the dependency-free early runtime before bootstrap.');
$assert(str_contains($publicEntrypoint, 'enforce_activation_gate(dirname(__DIR__))'), 'Public front controller must enforce the activation gate before bootstrap.');
$earlyRuntimeSource = file_get_contents($root . '/app/early_runtime.php') ?: '';
$assert(str_contains($earlyRuntimeSource, 'set_exception_handler'), 'Early runtime must register a real uncaught Throwable handler before bootstrap.');
$assert(str_contains($earlyRuntimeSource, 'E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR'), 'Early runtime shutdown handler must cover required fatal classes.');
$installerEntrypoint = file_get_contents($root . '/install.php') ?: '';
$assert(strpos($installerEntrypoint, 'enforce_activation_gate(__DIR__)') < strpos($installerEntrypoint, "require_once __DIR__ . '/app/migration_definitions.php'"), 'Installer must enforce the activation gate before loading application migration code.');

$updaterSource = file_get_contents($root . '/app/services/updates_jobs.php') ?: '';
$assert(str_contains($updaterSource, 'application_update_activation_gate_begin($job);'), 'Updater must persist activation state before publishing managed files.');
$assert(str_contains($updaterSource, "'app/early_runtime.php' => 35"), 'Early runtime must activate after ordinary dependencies but before HTTP front controllers.');
$assert(str_contains($updaterSource, "'install.php', 'reset.php' => 45"), 'Direct HTTP utility entrypoints must activate after the early gate and public front controller.');
$assert(strpos($updaterSource, "\$job['checkpoints']['activation_complete'] = true;") < strpos($updaterSource, "application_update_activation_gate_clear((string) \$job['id']);"), 'Updater must durably checkpoint complete activation before clearing the gate.');

$tmp = sys_get_temp_dir() . '/php-gallery-gate-test-' . bin2hex(random_bytes(5));
@mkdir($tmp . '/cache/updates/jobs/20260830200000-abcdefabcdef', 0777, true);
$marker = ['schema' => 1, 'job_id' => '20260830200000-abcdefabcdef', 'started_at' => time(), 'updated_at' => time()];
file_put_contents($tmp . '/cache/updates/activation.json', json_encode($marker));
$assert(!\Gallery\EarlyRuntime\activation_job_is_complete($tmp, $marker), 'Incomplete activation must not be reported as complete.');
$job = ['id' => $marker['job_id'], 'checkpoints' => ['activation_complete' => true]];
file_put_contents($tmp . '/cache/updates/jobs/' . $marker['job_id'] . '/job.json', json_encode($job));
$assert(\Gallery\EarlyRuntime\activation_job_is_complete($tmp, $marker), 'Durable activation_complete checkpoint must be recognized.');
@unlink($tmp . '/cache/updates/jobs/' . $marker['job_id'] . '/job.json');
@unlink($tmp . '/cache/updates/activation.json');
@rmdir($tmp . '/cache/updates/jobs/' . $marker['job_id']);
@rmdir($tmp . '/cache/updates/jobs');
@rmdir($tmp . '/cache/updates');
@rmdir($tmp . '/cache');
@rmdir($tmp);

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

echo "PASS: version 0.94 audit hardening focused regression checks\n";
