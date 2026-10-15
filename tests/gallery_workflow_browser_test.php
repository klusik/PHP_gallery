<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_workflow_browser_test.php
 * Module Type: Regression Test
 * Purpose: Register real browser workflow coverage in PHP regression.
 * Responsibilities:
 *   - Delegate to the disposable workflow runner under its existing opt-in guards.
 * Author: Rudolf Klusal
 * Real browser workflow registration through the ordinary central PHP regression suite.
 */
declare(strict_types=1);

require_once __DIR__ . '/support/gallery_workflow_safety.php';
use function GalleryWorkflow\check;
use function GalleryWorkflow\fixtureDatabase;
use function GalleryWorkflow\validateFixture;

$browser = (string) getenv('GALLERY_WORKFLOW_BROWSER');
if ($browser === 'disabled' && getenv('GALLERY_WORKFLOW_BROWSER_REQUIRED') !== '1') {
    echo "SKIP gallery workflow Chromium explicitly disabled by GALLERY_WORKFLOW_BROWSER\n";
    exit(0);
}
if (!getenv('GALLERY_WORKFLOW_FIXTURE') || $browser === '' || $browser === 'disabled') {
    $required = getenv('GALLERY_WORKFLOW_REQUIRED') === '1' || getenv('GALLERY_WORKFLOW_BROWSER_REQUIRED') === '1';
    echo ($required ? 'BLOCKED' : 'SKIP') . " gallery workflow full stack browser requires isolated fixture and Chromium\n";
    exit($required ? 1 : 0);
}
try {
    $token = (string) getenv('GALLERY_WORKFLOW_TOKEN');
    $directory = validateFixture((string) getenv('GALLERY_WORKFLOW_FIXTURE'), $token);
    check(is_file($browser), 'Browser unavailable.');
    $process = proc_open([(string) (getenv('GALLERY_WORKFLOW_NODE') ?: 'node'), __DIR__ . '/gallery_workflow_browser.mjs',
        $directory, $token, $browser], [0 => ['pipe', 'r'], 1 => STDOUT, 2 => ['file', $directory . '/node.log', 'w']],
        $pipes, dirname(__DIR__), null, ['bypass_shell' => true]);
    check(is_resource($process), 'Browser runner unavailable.');
    fclose($pipes[0]);
    $browserStatus = proc_close($process);
    if ($browserStatus !== 0) {
        // The Node runner emits only safe categorized fixture records; preserve
        // those in the central PHP child log before its temporary owner cleans up.
        $nodeLog = (string) @file_get_contents($directory . '/node.log');
        foreach (explode("\n", substr($nodeLog, -8192)) as $line) {
            if (preg_match('/^FAIL gallery workflow [a-zA-Z0-9 .:_()-]{1,500}$/D', trim($line)) === 1) {
                fwrite(STDERR, trim($line) . "\n");
            }
        }
    }
    check($browserStatus === 0, 'Browser journey failed; see the categorized browser fixture record.');
    $pdo = fixtureDatabase($directory, $token);
    $rows = $pdo->query("SELECT id, title, folder_path, visibility FROM galleries WHERE folder_path LIKE 'browser-workflow%'")->fetchAll(PDO::FETCH_ASSOC);
    check(count($rows) === 1 && $rows[0]['title'] === 'Browser edited 2', 'Browser duplicate suppression or edit persistence failed.');
    check($rows[0]['visibility'] === 'public', 'Browser visibility persistence failed.');
    $breadcrumbSetting = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
    $breadcrumbSetting->execute(['gallery_breadcrumb_style.' . (int) $rows[0]['id']]);
    check($breadcrumbSetting->fetchColumn() === 'gradient', 'Browser-selected gallery breadcrumb style was not persisted through the side panel.');
    $sidecar = json_decode((string) file_get_contents($directory . '/galleries/' . $rows[0]['folder_path'] . '/gallery.json'), true);
    check(($sidecar['title'] ?? '') === 'Browser edited 2', 'Browser sidecar mismatch.');
    check(($sidecar['breadcrumb_style'] ?? '') === 'gradient', 'Browser-selected breadcrumb style was not preserved in the gallery sidecar.');
    $statement = $pdo->prepare('SELECT relative_path, width, height FROM images WHERE gallery_id = ?');
    $statement->execute([$rows[0]['id']]);
    $images = $statement->fetchAll(PDO::FETCH_ASSOC);
    check(count($images) === 1 && (int) $images[0]['width'] === 48 && (int) $images[0]['height'] === 32, 'Browser upload or restore image persistence failed.');
    $result = json_decode((string) file_get_contents($directory . '/browser-result.json'), true, 512, JSON_THROW_ON_ERROR);
    check(preg_match('/^[a-f0-9]{64}$/D', (string) ($result['imageHash'] ?? '')) === 1, 'Browser upload digest missing.');
    check(hash_file('sha256', $directory . '/galleries/' . $rows[0]['folder_path'] . '/' . $images[0]['relative_path']) === $result['imageHash'],
        'Browser uploaded and restored original hash mismatch.');
    $statement = $pdo->prepare("SELECT COUNT(*) FROM gallery_trash_entries WHERE original_folder_path = ? AND status = 'restored'");
    $statement->execute([$rows[0]['folder_path']]);
    check((int) $statement->fetchColumn() === 1, 'Browser recoverable delete and restore ledger mismatch.');
    echo "PASS gallery workflow browser database and sidecar postconditions\n";
} catch (Throwable $exception) {
    // Assertions use authored labels. Database exceptions may contain private
    // connection details, so retain their category and argument-free locations.
    $reason = $exception instanceof PDOException ? 'database dependency or persistence failure' : $exception->getMessage();
    $reason = substr((string) preg_replace('/[^a-zA-Z0-9 .:_()-]/', ' ', $reason), 0, 400);
    fwrite(STDERR, 'FAIL gallery workflow ' . $reason . "\n");
    foreach (array_slice($exception->getTrace(), 0, 8) as $frame) {
        fwrite(STDERR, 'FAIL gallery workflow stack ' . basename((string) ($frame['file'] ?? 'unknown'))
            . ':' . (int) ($frame['line'] ?? 0) . ' ' . (string) ($frame['function'] ?? 'unknown') . "\n");
    }
    exit(1);
}
