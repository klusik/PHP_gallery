<?php
/**
 * Project: PHP Gallery
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
require __DIR__ . '/public/index.php';
