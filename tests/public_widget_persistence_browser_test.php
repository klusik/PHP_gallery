<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/public_widget_persistence_browser_test.php
 * Module Type: Regression Test
 * Purpose: Qualify public-widget persistence through the disposable live browser workflow.
 * Responsibilities:
 *   - Run a focused Chromium journey in the shared isolated application and database, reporting bounded media response and decode diagnostics on failure.
 *   - Remove only token-owned widget data and verify the exact initial table digest is restored.
 * Author: Rudolf Klusal
 * Browser persistence checks and exact disposable widget-state restoration.
 */
declare(strict_types=1);

require_once __DIR__ . '/support/gallery_workflow_safety.php';

use function GalleryWorkflow\check;
use function GalleryWorkflow\fixtureDatabase;
use function GalleryWorkflow\validateFixture;

$browser = (string) getenv('GALLERY_WORKFLOW_BROWSER');
$browserRequired = getenv('GALLERY_WORKFLOW_BROWSER_REQUIRED') === '1';
if ($browser === 'disabled' && !$browserRequired) {
    echo "SKIP public widget persistence browser explicitly disabled by GALLERY_WORKFLOW_BROWSER\n";
    exit(0);
}
if (!getenv('GALLERY_WORKFLOW_FIXTURE') || $browser === '' || $browser === 'disabled') {
    $required = getenv('GALLERY_WORKFLOW_REQUIRED') === '1' || $browserRequired;
    echo ($required ? 'BLOCKED' : 'SKIP') . " public widget persistence browser requires shared fixture and Chromium\n";
    exit($required ? 1 : 0);
}

$token = (string) getenv('GALLERY_WORKFLOW_TOKEN');
$directory = validateFixture((string) getenv('GALLERY_WORKFLOW_FIXTURE'), $token);
check(is_file($browser), 'Browser unavailable.');
$pdo = fixtureDatabase($directory, $token);
$endpoint = json_decode((string) file_get_contents($directory . '/endpoint.json'), true, 512, JSON_THROW_ON_ERROR);
$stateUrl = (string) ($endpoint['url'] ?? '') . '/__workflow_' . $token . '/preview-state';
$readWidgetHash = static function () use ($stateUrl): string {
    $request = curl_init($stateUrl);
    check($request !== false, 'Widget state probe could not start.');
    curl_setopt_array($request, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_PROXY => '']);
    $body = curl_exec($request);
    $status = (int) curl_getinfo($request, CURLINFO_RESPONSE_CODE);
    curl_close($request);
    check(is_string($body) && $status === 200, 'Widget state probe failed.');
    $state = json_decode($body, true);
    check(is_array($state) && preg_match('/^[a-f0-9]{64}$/D', (string) ($state['public_widgets_sha256'] ?? '')) === 1,
        'Widget state digest is unavailable.');
    return (string) $state['public_widgets_sha256'];
};
$widgetTitle = 'Workflow browser widget ' . $token;
$existing = $pdo->prepare('SELECT COUNT(*) FROM public_content_widgets WHERE title = ?');
$existing->execute([$widgetTitle]);
check((int) $existing->fetchColumn() === 0, 'Token-owned widget title already exists.');
$baselineHash = $readWidgetHash();
$removeOwnedWidget = static function () use ($pdo, $widgetTitle): void {
    $count = $pdo->prepare('SELECT COUNT(*) FROM public_content_widgets WHERE title = ?');
    $count->execute([$widgetTitle]);
    $ownedRows = (int) $count->fetchColumn();
    check($ownedRows <= 1, 'More than one token-owned widget row exists.');
    if ($ownedRows === 1) {
        $delete = $pdo->prepare('DELETE FROM public_content_widgets WHERE title = ?');
        $delete->execute([$widgetTitle]);
        check($delete->rowCount() === 1, 'Token-owned widget cleanup did not remove exactly one row.');
    }
};
$browserExitCode = 1;
try {
    $process = proc_open([(string) (getenv('GALLERY_WORKFLOW_NODE') ?: 'node'), __DIR__ . '/public_widget_persistence_browser.mjs',
        $directory, $token, $browser], [0 => ['pipe', 'r'], 1 => STDOUT,
        2 => ['file', $directory . '/node-widget.log', 'w']], $pipes, dirname(__DIR__), null, ['bypass_shell' => true]);
    check(is_resource($process), 'Widget persistence browser runner unavailable.');
    fclose($pipes[0]);
    $browserExitCode = proc_close($process);
} finally {
    $removeOwnedWidget();
    check($readWidgetHash() === $baselineHash, 'Widget persistence journey did not restore the exact initial widget state.');
}
check($browserExitCode === 0, 'Widget persistence browser journey failed.');
echo "PASS public widget persistence browser and exact fixture cleanup\n";
