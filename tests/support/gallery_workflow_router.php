<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/support/gallery_workflow_router.php
 * Module Type: Test Fixture
 * Purpose: Route requests inside the disposable application copy.
 * Responsibilities:
 *   - Keep fixture serving separate from the active installation.
 * Author: Rudolf Klusal
 * Router copied into the disposable application; never served by the active installation.
 */
declare(strict_types=1);

$token = (string) getenv('GALLERY_WORKFLOW_TOKEN');
if (PHP_SAPI !== 'cli-server' || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1'
    || !preg_match('/^[a-f0-9]{24}$/D', $token)
    || !hash_equals($token, (string) @file_get_contents(__DIR__ . '/.workflow-owner'))) {
    http_response_code(403);
    exit;
}
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/__workflow_' . $token . '/health') {
    header('Content-Type: text/plain');
    echo $token;
    return;
}
if ($path === '/__workflow_' . $token . '/preview-error') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        http_response_code(405);
        header('Allow: GET');
        return;
    }
    $reference = (string) ($_GET['reference'] ?? '');
    $summary = ['available' => false];
    if (preg_match('/^[A-F0-9]{16}$/D', $reference) === 1) {
        $log = @fopen(__DIR__ . '/http.log', 'rb');
        if (is_resource($log)) {
            // Read at most 1024 bounded chunks from this fixture-owned server log.
            for ($lineIndex = 0; $lineIndex < 1024 && ($line = fgets($log, 8192)) !== false; $lineIndex++) {
                // Emergency logging replaces namespace separators with dots; fatal categories use the fixed php-error-N form.
                if (preg_match('/\[PHP Gallery\] (?:uncaught|fatal) reference=' . preg_quote($reference, '/')
                    . ' type=((?:php-error-[0-9]+)|(?:[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*)) location=(.+?):([1-9][0-9]*) message=/', trim($line), $match) !== 1) {
                    continue;
                }
                $source = basename(str_replace('\\', '/', $match[2]));
                if (preg_match('/^[A-Za-z0-9_.-]{1,100}$/D', $source) !== 1) {
                    break;
                }
                $summary = ['available' => true, 'type' => $match[1], 'source' => $source, 'line' => (int) $match[3]];
                break;
            }
            fclose($log);
        }
    }
    header('Cache-Control: no-store');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($summary, JSON_THROW_ON_ERROR);
    return;
}
if ($path === '/__workflow_' . $token . '/preview-state') {
    $snapshotStage = 'bootstrap';
    try {
        require_once __DIR__ . '/app/bootstrap_full.php';
        $pdo = \Gallery\Core\db();
        $snapshotStage = 'telemetry_events';
        $telemetryCount = (int) $pdo->query('SELECT COUNT(*) FROM telemetry_events')->fetchColumn();
        $snapshotStage = 'telemetry_sessions';
        $telemetrySessionCount = (int) $pdo->query('SELECT COUNT(*) FROM telemetry_sessions')->fetchColumn();
        $snapshotStage = 'telemetry_hourly_metrics';
        $telemetryHourlyCount = (int) $pdo->query('SELECT COUNT(*) FROM telemetry_hourly_metrics')->fetchColumn();
        $snapshotStage = 'thumbnail_variants';
        $variants = $pdo->query("SELECT v.id, v.image_id, v.size_px, v.format, v.derivative_version, v.width, v.height,
                v.file_size, v.modified_at, v.status, v.status_reason, v.checked_at, v.created_at, v.updated_at
            FROM image_thumbnail_variants v
            INNER JOIN images i ON i.id = v.image_id
            WHERE i.gallery_id = (SELECT id FROM galleries WHERE folder_path = 'seed' LIMIT 1)
            ORDER BY v.id")->fetchAll(PDO::FETCH_ASSOC);
        $snapshotStage = 'image_metadata';
        $imageMetadata = $pdo->query("SELECT id, thumbnail_metadata_refreshed_at FROM images
            WHERE gallery_id = (SELECT id FROM galleries WHERE folder_path = 'seed' LIMIT 1) ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $snapshotStage = 'gallery_files';
        $galleryFiles = [];
        $galleryRoot = __DIR__ . '/galleries/seed';
        if (is_dir($galleryRoot)) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($galleryRoot, FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->isFile() && !$file->isLink()) {
                    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($galleryRoot) + 1));
                    $galleryFiles[$relative] = ['size' => $file->getSize(), 'modified' => $file->getMTime(),
                        'sha256' => hash_file('sha256', $file->getPathname())];
                }
            }
            ksort($galleryFiles);
        }
        header('Cache-Control: no-store');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['telemetry_events' => $telemetryCount, 'telemetry_sessions' => $telemetrySessionCount,
            'telemetry_hourly_metrics' => $telemetryHourlyCount, 'thumbnail_variants' => $variants,
            'image_metadata' => $imageMetadata, 'gallery_files' => $galleryFiles], JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        // Keep disposable-server diagnostics useful without returning SQL messages, tokens or filesystem paths.
        http_response_code(500);
        header('Cache-Control: no-store');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'snapshot_failed', 'stage' => $snapshotStage,
            'type' => get_class($exception), 'source' => basename($exception->getFile()),
            'line' => $exception->getLine()], JSON_THROW_ON_ERROR);
    }
    return;
}
header("Content-Security-Policy: connect-src 'self'; img-src 'self' data: blob:; frame-src 'self'");
if ($path === '/__workflow_' . $token) {
    header('Cache-Control: no-store');
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>Isolated workflow</title><body>';
    echo '<script id="credentials" type="application/json">' . json_encode(json_decode((string) file_get_contents(__DIR__ . '/seed.json'), true), JSON_HEX_TAG) . '</script>';
    echo '<iframe id="application" title="Disposable gallery" style="width:1280px;height:900px"></iframe>';
    echo '<script src="/__workflow_' . $token . '.js"></script></body></html>';
    return;
}
if ($path === '/__workflow_' . $token . '.js') {
    header('Content-Type: text/javascript');
    readfile(__DIR__ . '/browser.js');
    return;
}
if ($path === '/__workflow_' . $token . '/result' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = json_decode((string) file_get_contents('php://input', false, null, 0, 4096), true);
    if (is_array($result) && is_bool($result['ok'] ?? null) && preg_match('/^[a-zA-Z0-9 ._-]{1,100}$/D', (string) ($result['stage'] ?? ''))) {
        file_put_contents(__DIR__ . '/browser-result.json', json_encode($result, JSON_THROW_ON_ERROR));
    }
    http_response_code(204);
    return;
}
// The PHP built-in server would otherwise serve existing files outside the app's media authorization.
// Only public assets are allowed through; originals, runtime files and config are never static routes.
if (str_starts_with($path, '/assets/')) {
    $target = realpath(__DIR__ . '/public' . $path);
    if ($target && str_starts_with(str_replace('\\', '/', $target), str_replace('\\', '/', __DIR__) . '/public/assets/')) return false;
    http_response_code(404);
    return;
}
// The router script is the PHP server's entry point, but application routing expects SCRIPT_NAME to
// identify the included public front controller. Keep clean asset paths out of the inferred mount path.
$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__ . '/public/index.php';
