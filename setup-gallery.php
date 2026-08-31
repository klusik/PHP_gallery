<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: setup-gallery.php
 * Module Type: Setup Utility
 *
 * Purpose:
 *   Provides setup, installation, or recovery functionality for PHP Gallery.
 *
 * Responsibilities:
 *   - Guide setup or recovery workflow
 *   - Validate configuration before writing changes
 *   - Avoid exposing sensitive details to public users
 *
 * Author:
 *   Rudolf Klusal
 *
 * Contact:
 *   https://github.com/klusik
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Prefer small, readable changes over broad rewrites.
 *
 * Last Updated:
 *   2026-05-04
 */

declare(strict_types=1);

const GALLERY_BOOTSTRAP_ARCHIVE_URL = 'https://github.com/klusik/PHP_gallery/archive/refs/heads/main.zip';
const GALLERY_BOOTSTRAP_ARCHIVE_LABEL = 'PHP_gallery main branch archive';

session_name('gallery_cms_bootstrap_installer');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// $root stores an intermediate value used by the surrounding gallery workflow.
$root = __DIR__;
// $configFile stores an intermediate value used by the surrounding gallery workflow.
$configFile = $root . '/config.php';
// $cachePath stores an intermediate value used by the surrounding gallery workflow.
$cachePath = $root . '/cache';
// $installLockFile stores an intermediate value used by the surrounding gallery workflow.
$installLockFile = $cachePath . '/installed.lock';
// $bootstrapLockFile stores an intermediate value used by the surrounding gallery workflow.
$bootstrapLockFile = $cachePath . '/bootstrap-installed.lock';
// $errors stores an intermediate value used by the surrounding gallery workflow.
$errors = [];
// $messages stores an intermediate value used by the surrounding gallery workflow.
$messages = [];
// $downloadStarted stores an intermediate value used by the surrounding gallery workflow.
$downloadStarted = false;

if (empty($_SESSION['bootstrap_token'])) {
    $_SESSION['bootstrap_token'] = bin2hex(random_bytes(24));
}

if (is_file($configFile) || is_file($installLockFile)) {
    gallery_bootstrap_render_locked($configFile, $installLockFile);
    exit;
}

if (is_file($bootstrapLockFile)) {
    gallery_bootstrap_render_already_downloaded();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // $downloadStarted stores an intermediate value used by the surrounding gallery workflow.
    $downloadStarted = true;
    // $postedToken stores an intermediate value used by the surrounding gallery workflow.
    $postedToken = (string) ($_POST['bootstrap_token'] ?? '');

    if (!hash_equals((string) $_SESSION['bootstrap_token'], $postedToken)) {
        $errors[] = 'Security token mismatch. Reload this page and try again.';
    }

    if (!$errors) {
        [$messages, $errors] = gallery_bootstrap_run($root, $cachePath, $bootstrapLockFile);
    }

    if (!$errors) {
        header('Location: install.php', true, 303);
        exit;
    }
}

gallery_bootstrap_render_page($messages, $errors, $downloadStarted);

/**
 * Render the main bootstrap installer page.
 *
 * @param array $messages Status messages for the caller.
 * @param array $errors Error messages for the caller.
 * @param bool $downloadStarted Download started value.
 */
function gallery_bootstrap_render_page(array $messages, array $errors, bool $downloadStarted): void
{
    // $checks stores an intermediate value used by the surrounding gallery workflow.
    $checks = gallery_bootstrap_environment_checks(__DIR__);
    // $hasBlockingCheck stores an intermediate value used by the surrounding gallery workflow.
    $hasBlockingCheck = false;
    foreach ($checks as $check) {
        if (!$check['ok'] && $check['required']) {
            // $hasBlockingCheck stores an intermediate value used by the surrounding gallery workflow.
            $hasBlockingCheck = true;
            break;
        }
    }

    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>PHP Gallery Bootstrap Installer</title>';
    echo '<style>' . gallery_bootstrap_css() . '</style></head><body><main class="card">';
    echo '<p class="eyebrow">PHP Gallery CMS</p>';
    echo '<h1>Bootstrap installer</h1>';
    echo '<p class="lead">This single PHP file downloads the gallery package from GitHub, unpacks it into this directory, and then opens the normal <code>install.php</code> setup wizard.</p>';

    if ($messages) {
        echo '<section class="notice good"><h2>Completed steps</h2><ul>';
        foreach ($messages as $message) {
            echo '<li>' . gallery_bootstrap_e($message) . '</li>';
        }
        echo '</ul></section>';
    }

    if ($errors) {
        echo '<section class="notice bad"><h2>Installer stopped</h2><ul>';
        foreach ($errors as $error) {
            echo '<li>' . gallery_bootstrap_e($error) . '</li>';
        }
        echo '</ul></section>';
    }

    echo '<section><h2>Environment check</h2><div class="checks">';
    foreach ($checks as $check) {
        // $className stores an intermediate value used by the surrounding gallery workflow.
        $className = $check['ok'] ? 'check ok' : ($check['required'] ? 'check fail' : 'check warn');
        // $status stores an intermediate value used by the surrounding gallery workflow.
        $status = $check['ok'] ? 'OK' : ($check['required'] ? 'Required' : 'Optional');
        echo '<div class="' . $className . '"><strong>' . gallery_bootstrap_e($check['label']) . '</strong><span>' . gallery_bootstrap_e($status) . '</span><p>' . gallery_bootstrap_e($check['detail']) . '</p></div>';
    }
    echo '</div></section>';

    echo '<section class="details"><h2>What will happen</h2><ol>';
    echo '<li>Download <code>' . gallery_bootstrap_e(GALLERY_BOOTSTRAP_ARCHIVE_LABEL) . '</code>.</li>';
    echo '<li>Extract the archive into a temporary folder.</li>';
    echo '<li>Copy the project files into the current web directory.</li>';
    echo '<li>Create <code>cache/bootstrap-installed.lock</code> so this downloader cannot run twice.</li>';
    echo '<li>Redirect you to <code>install.php</code> to configure the database and admin account.</li>';
    echo '</ol></section>';

    echo '<form method="post">';
    echo '<input type="hidden" name="bootstrap_token" value="' . gallery_bootstrap_e((string) $_SESSION['bootstrap_token']) . '">';
    echo '<button type="submit"' . ($hasBlockingCheck ? ' disabled' : '') . '>' . ($downloadStarted ? 'Try again' : 'Download and start installer') . '</button>';
    echo '</form>';

    if ($hasBlockingCheck) {
        echo '<p class="muted">Fix the required checks above before running the bootstrap installer.</p>';
    }

    echo '<p class="muted">For security, keep this file only for the initial deployment. It refuses to run after <code>config.php</code>, <code>cache/installed.lock</code>, or <code>cache/bootstrap-installed.lock</code> exists.</p>';
    echo '</main></body></html>';
}

/**
 * Run download, extraction, copy, and lock creation.
 *
 * @param string $root Root value.
 * @param string $cachePath Cache path filesystem path.
 * @param string $bootstrapLockFile Bootstrap lock file value.
 * @return array Structured result data for the caller.
 */
function gallery_bootstrap_run(string $root, string $cachePath, string $bootstrapLockFile): array
{
    // $messages stores an intermediate value used by the surrounding gallery workflow.
    $messages = [];
    // $errors stores an intermediate value used by the surrounding gallery workflow.
    $errors = [];

    if (!gallery_bootstrap_ensure_directory($cachePath)) {
        return [$messages, ['Cannot create or write the cache directory: ' . $cachePath]];
    }

    // $temporaryRoot stores an intermediate value used by the surrounding gallery workflow.
    $temporaryRoot = $cachePath . '/bootstrap-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4));
    // $archivePath stores an intermediate value used by the surrounding gallery workflow.
    $archivePath = $temporaryRoot . '/package.zip';
    // $extractPath stores an intermediate value used by the surrounding gallery workflow.
    $extractPath = $temporaryRoot . '/extract';

    if (!mkdir($temporaryRoot, 0755, true) && !is_dir($temporaryRoot)) {
        return [$messages, ['Cannot create temporary directory: ' . $temporaryRoot]];
    }

    if (!mkdir($extractPath, 0755, true) && !is_dir($extractPath)) {
        gallery_bootstrap_remove_tree($temporaryRoot);
        return [$messages, ['Cannot create extraction directory: ' . $extractPath]];
    }

    try {
        gallery_bootstrap_download(GALLERY_BOOTSTRAP_ARCHIVE_URL, $archivePath);
        $messages[] = 'Downloaded the GitHub archive.';

        // $projectRoot stores an intermediate value used by the surrounding gallery workflow.
        $projectRoot = gallery_bootstrap_extract($archivePath, $extractPath);
        $messages[] = 'Extracted the archive.';

        gallery_bootstrap_validate_project_root($projectRoot);
        $messages[] = 'Validated the extracted gallery package.';

        gallery_bootstrap_copy_tree($projectRoot, $root, [basename(__FILE__), 'config.php']);
        $messages[] = 'Copied gallery files into the current web directory.';

        gallery_bootstrap_write_lock($bootstrapLockFile);
        $messages[] = 'Created cache/bootstrap-installed.lock.';

        gallery_bootstrap_remove_tree($temporaryRoot);
        $messages[] = 'Cleaned temporary installer files.';
    } catch (Throwable $exception) {
        gallery_bootstrap_remove_tree($temporaryRoot);
        $errors[] = $exception->getMessage();
    }

    return [$messages, $errors];
}

/**
 * Download a remote file using cURL when available, otherwise PHP streams.
 *
 * @param string $url URL used by this workflow.
 * @param string $targetPath Target filesystem path.
 */
function gallery_bootstrap_download(string $url, string $targetPath): void
{
    if (function_exists('curl_init')) {
        // $targetHandle stores an intermediate value used by the surrounding gallery workflow.
        $targetHandle = fopen($targetPath, 'wb');
        if ($targetHandle === false) {
            throw new RuntimeException('Cannot write downloaded archive: ' . $targetPath);
        }

        // $curlHandle stores an intermediate value used by the surrounding gallery workflow.
        $curlHandle = curl_init($url);
        if ($curlHandle === false) {
            fclose($targetHandle);
            throw new RuntimeException('Cannot initialize cURL.');
        }

        curl_setopt_array($curlHandle, [
            CURLOPT_FILE => $targetHandle,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_USERAGENT => 'PHP Gallery CMS Bootstrap Installer',
            CURLOPT_FAILONERROR => true,
        ]);

        // $result stores an intermediate value used by the surrounding gallery workflow.
        $result = curl_exec($curlHandle);
        // $error stores an intermediate value used by the surrounding gallery workflow.
        $error = curl_error($curlHandle);
        // $statusCode stores an intermediate value used by the surrounding gallery workflow.
        $statusCode = (int) curl_getinfo($curlHandle, CURLINFO_RESPONSE_CODE);
        curl_close($curlHandle);
        fclose($targetHandle);

        if ($result !== true) {
            @unlink($targetPath);
            throw new RuntimeException('GitHub download failed via cURL. HTTP status: ' . $statusCode . '. ' . $error);
        }
    } else {
        if (!ini_get('allow_url_fopen')) {
            throw new RuntimeException('Neither cURL nor allow_url_fopen is available. Upload the full project ZIP manually or enable one of them on the server.');
        }

        // $context stores an intermediate value used by the surrounding gallery workflow.
        $context = stream_context_create([
            'http' => [
                'follow_location' => 1,
                'timeout' => 120,
                'header' => "User-Agent: PHP Gallery CMS Bootstrap Installer\r\n",
            ],
            'https' => [
                'timeout' => 120,
            ],
        ]);

        // $downloadedBytes stores an intermediate value used by the surrounding gallery workflow.
        $downloadedBytes = @file_put_contents($targetPath, fopen($url, 'rb', false, $context));
        if ($downloadedBytes === false) {
            @unlink($targetPath);
            throw new RuntimeException('GitHub download failed via PHP streams.');
        }
    }

    if (!is_file($targetPath) || filesize($targetPath) < 1024) {
        @unlink($targetPath);
        throw new RuntimeException('Downloaded archive is missing or unexpectedly small.');
    }
}

/**
 * Extract a ZIP archive and return the detected project root directory.
 *
 * @param string $archivePath Archive path filesystem path.
 * @param string $extractPath Extract path filesystem path.
 * @return string Text result for the caller.
 */
function gallery_bootstrap_extract(string $archivePath, string $extractPath): string
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('PHP ZipArchive extension is missing. Ask the host to enable zip support or upload the extracted project manually.');
    }

    // $zip stores an intermediate value used by the surrounding gallery workflow.
    $zip = new ZipArchive();
    // $openResult stores an intermediate value used by the surrounding gallery workflow.
    $openResult = $zip->open($archivePath);
    if ($openResult !== true) {
        throw new RuntimeException('Cannot open downloaded ZIP archive. ZipArchive error code: ' . $openResult);
    }

    for ($index = 0; $index < $zip->numFiles; $index++) {
        // $entryName stores an intermediate value used by the surrounding gallery workflow.
        $entryName = (string) $zip->getNameIndex($index);
        if (gallery_bootstrap_zip_entry_is_unsafe($entryName)) {
            $zip->close();
            throw new RuntimeException('Unsafe ZIP entry detected: ' . $entryName);
        }
    }

    if (!$zip->extractTo($extractPath)) {
        $zip->close();
        throw new RuntimeException('Cannot extract downloaded ZIP archive.');
    }

    $zip->close();

    // $entries stores an intermediate value used by the surrounding gallery workflow.
    $entries = array_values(array_filter(scandir($extractPath) ?: [], static function (string $entry): bool {
        return $entry !== '.' && $entry !== '..';
    }));

    if (count($entries) === 1 && is_dir($extractPath . '/' . $entries[0])) {
        return $extractPath . '/' . $entries[0];
    }

    return $extractPath;
}

/**
 * Check whether a ZIP entry can escape the extraction directory.
 *
 * @param string $entryName Entry name value.
 * @return bool True when the condition matches.
 */
function gallery_bootstrap_zip_entry_is_unsafe(string $entryName): bool
{
    // $normalized stores an intermediate value used by the surrounding gallery workflow.
    $normalized = str_replace('\\', '/', $entryName);
    return $normalized === ''
        || str_starts_with($normalized, '/')
        || preg_match('#(^|/)\.\.(/|$)#', $normalized) === 1
        || preg_match('#^[A-Za-z]:/#', $normalized) === 1;
}

/**
 * Confirm that the extracted archive looks like PHP Gallery CMS.
 *
 * @param string $projectRoot Project root value.
 */
function gallery_bootstrap_validate_project_root(string $projectRoot): void
{
    // $requiredFiles stores an intermediate value used by the surrounding gallery workflow.
    $requiredFiles = ['index.php', 'install.php', 'app/bootstrap.php', 'config.example.php'];
    foreach ($requiredFiles as $requiredFile) {
        if (!is_file($projectRoot . '/' . $requiredFile)) {
            throw new RuntimeException('The downloaded archive does not look like PHP Gallery CMS. Missing file: ' . $requiredFile);
        }
    }
}

/**
 * Copy a directory tree into another directory while preserving existing excluded files.
 *
 * @param string $sourcePath Source filesystem path.
 * @param string $targetPath Target filesystem path.
 * @param array $excludedRootFiles Excluded root files value.
 */
function gallery_bootstrap_copy_tree(string $sourcePath, string $targetPath, array $excludedRootFiles = []): void
{
    // $directory stores an intermediate value used by the surrounding gallery workflow.
    $directory = opendir($sourcePath);
    if ($directory === false) {
        throw new RuntimeException('Cannot read extracted project directory: ' . $sourcePath);
    }

    if (!is_dir($targetPath) && !mkdir($targetPath, 0755, true)) {
        closedir($directory);
        throw new RuntimeException('Cannot create target directory: ' . $targetPath);
    }

    while (($entry = readdir($directory)) !== false) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        if (in_array($entry, $excludedRootFiles, true)) {
            continue;
        }

        // $sourceEntry stores an intermediate value used by the surrounding gallery workflow.
        $sourceEntry = $sourcePath . '/' . $entry;
        // $targetEntry stores an intermediate value used by the surrounding gallery workflow.
        $targetEntry = $targetPath . '/' . $entry;

        if (is_dir($sourceEntry)) {
            gallery_bootstrap_copy_tree($sourceEntry, $targetEntry);
            continue;
        }

        if (!copy($sourceEntry, $targetEntry)) {
            closedir($directory);
            throw new RuntimeException('Cannot copy file to target directory: ' . $targetEntry);
        }
    }

    closedir($directory);
}

/**
 * Create a directory and verify that it is writable.
 *
 * @param string $path Filesystem path.
 * @return bool True when the condition matches.
 */
function gallery_bootstrap_ensure_directory(string $path): bool
{
    if (!is_dir($path) && !mkdir($path, 0755, true)) {
        return false;
    }

    return is_writable($path);
}

/**
 * Write the bootstrap lock file.
 *
 * @param string $path Filesystem path.
 */
function gallery_bootstrap_write_lock(string $path): void
{
    // $directory stores an intermediate value used by the surrounding gallery workflow.
    $directory = dirname($path);
    if (!gallery_bootstrap_ensure_directory($directory)) {
        throw new RuntimeException('Cannot write lock directory: ' . $directory);
    }

    // $content stores an intermediate value used by the surrounding gallery workflow.
    $content = 'Bootstrap installer completed at ' . date('c') . PHP_EOL;
    if (file_put_contents($path, $content, LOCK_EX) === false) {
        throw new RuntimeException('Cannot write bootstrap lock file: ' . $path);
    }
}

/**
 * Remove a directory tree recursively.
 *
 * @param string $path Filesystem path.
 */
function gallery_bootstrap_remove_tree(string $path): void
{
    if (!file_exists($path)) {
        return;
    }

    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }

    // $items stores an intermediate value used by the surrounding gallery workflow.
    $items = scandir($path);
    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        gallery_bootstrap_remove_tree($path . '/' . $item);
    }

    @rmdir($path);
}

/**
 * Build installer environment checks.
 *
 * @param string $root Root value.
 * @return array Structured result data for the caller.
 */
function gallery_bootstrap_environment_checks(string $root): array
{
    return [
        [
            'label' => 'PHP version',
            'ok' => version_compare(PHP_VERSION, '8.1.0', '>='),
            'required' => true,
            'detail' => 'Detected PHP ' . PHP_VERSION . '. PHP 8.1 or newer is required.',
        ],
        [
            'label' => 'Target directory writable',
            'ok' => is_writable($root),
            'required' => true,
            'detail' => $root,
        ],
        [
            'label' => 'ZIP support',
            'ok' => class_exists('ZipArchive'),
            'required' => true,
            'detail' => 'ZipArchive is required to unpack the downloaded project archive.',
        ],
        [
            'label' => 'Outbound HTTPS download',
            'ok' => function_exists('curl_init') || (bool) ini_get('allow_url_fopen'),
            'required' => true,
            'detail' => 'The installer needs cURL or allow_url_fopen to download from GitHub.',
        ],
        [
            'label' => 'PDO MySQL',
            'ok' => extension_loaded('pdo_mysql'),
            'required' => false,
            'detail' => 'The next install.php step needs PDO MySQL for the database connection.',
        ],
        [
            'label' => 'GD image library',
            'ok' => extension_loaded('gd'),
            'required' => false,
            'detail' => 'GD is used later for thumbnail generation.',
        ],
    ];
}

/**
 * Render the page shown after the real application already exists.
 *
 * @param string $configFile Config file value.
 * @param string $installLockFile Install lock file value.
 */
function gallery_bootstrap_render_locked(string $configFile, string $installLockFile): void
{
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Bootstrap installer locked</title><style>' . gallery_bootstrap_css() . '</style></head><body><main class="card">';
    echo '<p class="eyebrow">PHP Gallery CMS</p><h1>Bootstrap installer locked</h1>';
    echo '<p>This gallery already appears to be installed. The bootstrap installer refuses to run when <code>config.php</code> or <code>cache/installed.lock</code> exists.</p>';
    echo '<ul><li><code>config.php</code></li><li><code>cache/installed.lock</code></li></ul>';
    echo '<p><a class="buttonlike" href="index.php">Open gallery</a></p>';
    echo '</main></body></html>';
}

/**
 * Render the page shown after the package was already downloaded.
 */
function gallery_bootstrap_render_already_downloaded(): void
{
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Bootstrap already completed</title><style>' . gallery_bootstrap_css() . '</style></head><body><main class="card">';
    echo '<p class="eyebrow">PHP Gallery CMS</p><h1>Bootstrap already completed</h1>';
    echo '<p>The gallery files were already downloaded and unpacked. Continue with the normal installer.</p>';
    echo '<p><a class="buttonlike" href="install.php">Open install.php</a></p>';
    echo '</main></body></html>';
}

/**
 * Escape one value for safe HTML output.
 *
 * @param string $value Value to process.
 * @return string Text result for the caller.
 */
function gallery_bootstrap_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Return the compact CSS used by the standalone installer.
 *
 * @return string Text result for the caller.
 */
function gallery_bootstrap_css(): string
{
    return 'body{margin:0;min-height:100vh;background:#f4efe7;color:#17212b;font-family:Arial,Helvetica,sans-serif;display:flex;align-items:center;justify-content:center;padding:24px}.card{width:min(920px,100%);background:rgba(255,255,255,.86);border:1px solid rgba(23,33,43,.12);border-radius:24px;box-shadow:0 24px 80px rgba(31,41,55,.18);padding:32px}.eyebrow{text-transform:uppercase;letter-spacing:.14em;font-size:12px;color:#0f766e;font-weight:700;margin:0 0 10px}h1{margin:0 0 14px;font-size:34px}h2{font-size:18px;margin:24px 0 12px}.lead{font-size:17px;line-height:1.55;color:#334155}.muted{color:#64748b;font-size:14px;line-height:1.5}code{background:#eef2f7;padding:2px 6px;border-radius:6px}.checks{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px}.check{border-radius:16px;padding:14px;border:1px solid rgba(23,33,43,.12);background:#fff}.check strong{display:block}.check span{display:inline-block;margin-top:8px;font-size:12px;font-weight:700}.check p{margin:8px 0 0;color:#64748b;font-size:13px;line-height:1.4}.check.ok span{color:#047857}.check.fail span{color:#b91c1c}.check.warn span{color:#b45309}.notice{border-radius:16px;padding:16px;margin:18px 0}.notice.good{background:#ecfdf5;border:1px solid #a7f3d0}.notice.bad{background:#fef2f2;border:1px solid #fecaca}.details ol{line-height:1.7}button,.buttonlike{display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:999px;background:#0f766e;color:#fff;text-decoration:none;font-weight:700;padding:12px 20px;cursor:pointer;font-size:15px}button:disabled{opacity:.45;cursor:not-allowed}ul{line-height:1.7}';
}
