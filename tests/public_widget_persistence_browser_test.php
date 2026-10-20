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

/** Bound the retained Chromium stderr tail for one failed workflow child.
 * Type: int. Units: bytes. Scope: one failed public-widget browser result.
 * Consumers: the owned cache/test-audit evidence directory.
 * Rationale: retain recent browser diagnostics without copying unbounded logs. Value: 65536.
 */
const PUBLIC_WIDGET_BROWSER_ARTIFACT_LOG_MAX_BYTES = 65536;

/** Bound the retained structured result from the loopback fixture.
 * Type: int. Units: bytes. Scope: one failed public-widget browser result.
 * Consumers: the owned cache/test-audit evidence directory.
 * Rationale: the endpoint accepts a 4096-byte request and stores only bounded fields. Value: 4096.
 */
const PUBLIC_WIDGET_BROWSER_ARTIFACT_RESULT_MAX_BYTES = 4096;

/** Restrict the retained child artifact directory to its owner.
 * Type: int. Units: POSIX permission bits. Scope: one failed public-widget browser result.
 * Consumers: retained node log and child result JSON.
 * Rationale: CI artifacts contain fixture diagnostics and should not be shared with other local accounts. Value: 0700.
 */
const PUBLIC_WIDGET_BROWSER_ARTIFACT_DIRECTORY_MODE = 0700;

/** Restrict generated evidence parent directories to repository-account access.
 * Type: int. Units: POSIX permission bits. Scope: cache/test-audit parents created by this regression.
 * Consumers: failure artifact directory setup only.
 * Rationale: the report runner owns these directories and CI must collect them without broad access. Value: 0700.
 */
const PUBLIC_WIDGET_BROWSER_ARTIFACT_PARENT_DIRECTORY_MODE = 0700;

/** Bound the stage label accepted from the disposable browser result.
 * Type: int. Units: characters. Scope: one public-widget browser child result.
 * Consumers: fixture result safety validation before logging.
 * Rationale: match the loopback router's existing compact stage contract. Value: 100.
 */
const PUBLIC_WIDGET_BROWSER_RESULT_STAGE_MAX_CHARACTERS = 100;

/** Bound safe diagnostic text accepted from the disposable browser result.
 * Type: int. Units: characters. Scope: one public-widget browser child result.
 * Consumers: fixture result safety validation before logging.
 * Rationale: match the loopback router's existing bounded detail contract. Value: 180.
 */
const PUBLIC_WIDGET_BROWSER_RESULT_DETAIL_MAX_CHARACTERS = 180;

$browser = (string) getenv('GALLERY_WORKFLOW_BROWSER');
$browserRequired = getenv('GALLERY_WORKFLOW_BROWSER_REQUIRED') === '1';
if ($browser === 'disabled' && !$browserRequired) {
    echo
        "SKIP public widget persistence browser explicitly disabled by GALLERY_WORKFLOW_BROWSER\n";
    exit(0);
}
if (
    !getenv('GALLERY_WORKFLOW_FIXTURE')
    || $browser === ''
    || $browser === 'disabled'
) {
    $required = getenv('GALLERY_WORKFLOW_REQUIRED') === '1' || $browserRequired;
    echo
        ($required ? 'BLOCKED' : 'SKIP')
            . " public widget persistence browser requires shared fixture and Chromium\n";
    exit($required ? 1 : 0);
}

$token = (string) getenv('GALLERY_WORKFLOW_TOKEN');
$directory = validateFixture(
    (string) getenv('GALLERY_WORKFLOW_FIXTURE'),
    $token,
);
$preserveBrowserEvidence = static function () use ($directory, $token): string {
    $repositoryRoot = realpath(dirname(__DIR__));
    check(is_string($repositoryRoot), 'Repository root unavailable for browser evidence.');
    $cacheRoot = $repositoryRoot . DIRECTORY_SEPARATOR . 'cache';
    if (!is_dir($cacheRoot)) {
        check(
            @mkdir(
                $cacheRoot,
                PUBLIC_WIDGET_BROWSER_ARTIFACT_PARENT_DIRECTORY_MODE,
                true,
            ),
            'Browser evidence cache root could not be created.',
        );
    }
    check(!is_link($cacheRoot), 'Browser evidence cache root must not be linked.');
    $auditRoot = $cacheRoot . DIRECTORY_SEPARATOR . 'test-audit';
    if (!is_dir($auditRoot)) {
        check(
            @mkdir(
                $auditRoot,
                PUBLIC_WIDGET_BROWSER_ARTIFACT_PARENT_DIRECTORY_MODE,
            ),
            'Browser evidence audit directory could not be created.',
        );
    }
    check(!is_link($auditRoot), 'Browser evidence audit directory must not be linked.');
    $resolvedCacheRoot = realpath($cacheRoot);
    $resolvedAuditRoot = realpath($auditRoot);
    check(
        is_string($resolvedCacheRoot)
            && is_string($resolvedAuditRoot)
            && dirname($resolvedAuditRoot) === $resolvedCacheRoot,
        'Browser evidence directory escaped the repository cache.',
    );
    $artifactDirectory = $resolvedAuditRoot
        . DIRECTORY_SEPARATOR . 'public-widget-persistence-' . $token;
    check(
        !file_exists($artifactDirectory)
            && !is_link($artifactDirectory)
            && @mkdir(
                $artifactDirectory,
                PUBLIC_WIDGET_BROWSER_ARTIFACT_DIRECTORY_MODE,
            ),
        'Owned browser evidence directory could not be created.',
    );
    foreach ([
        'node-widget.log' => PUBLIC_WIDGET_BROWSER_ARTIFACT_LOG_MAX_BYTES,
        'browser-widget-result.json' => PUBLIC_WIDGET_BROWSER_ARTIFACT_RESULT_MAX_BYTES,
    ] as $filename => $maximumBytes) {
        $sourcePath = $directory . DIRECTORY_SEPARATOR . $filename;
        if (!is_file($sourcePath) || is_link($sourcePath)) {
            continue;
        }
        $sourceSize = filesize($sourcePath);
        $source = @fopen($sourcePath, 'rb');
        check(
            is_int($sourceSize) && is_resource($source),
            'Owned browser evidence file could not be read.',
        );
        $offset = max(0, $sourceSize - $maximumBytes);
        if ($offset > 0) {
            check(fseek($source, $offset) === 0, 'Browser log tail could not be selected.');
        }
        $contents = fread($source, $maximumBytes);
        fclose($source);
        check(
            is_string($contents)
                && file_put_contents(
                    $artifactDirectory . DIRECTORY_SEPARATOR . $filename,
                    $contents,
                    LOCK_EX,
                ) === strlen($contents),
            'Owned browser evidence file could not be retained.',
        );
    }
    return $artifactDirectory;
};
check(is_file($browser), 'Browser unavailable.');
$pdo = fixtureDatabase($directory, $token);
$endpoint = json_decode(
    (string) file_get_contents($directory . '/endpoint.json'),
    true,
    512,
    JSON_THROW_ON_ERROR,
);
$stateUrl =
    (string) ($endpoint['url'] ?? '')
    . '/__workflow_'
    . $token
    . '/preview-state';
$readWidgetHash = static function () use ($stateUrl): string {
    $request = curl_init($stateUrl);
    check($request !== false, 'Widget state probe could not start.');
    curl_setopt_array($request, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_PROXY => '',
    ]);
    $body = curl_exec($request);
    $status = (int) curl_getinfo($request, CURLINFO_RESPONSE_CODE);
    curl_close($request);
    check(is_string($body) && $status === 200, 'Widget state probe failed.');
    $state = json_decode($body, true);
    check(
        is_array($state)
            && preg_match(
                '/^[a-f0-9]{64}$/D',
                (string) ($state['public_widgets_sha256'] ?? ''),
            ) === 1,
        'Widget state digest is unavailable.',
    );
    return (string) $state['public_widgets_sha256'];
};
$widgetTitle = 'Workflow browser widget ' . $token;
$existing = $pdo->prepare(
    'SELECT COUNT(*) FROM public_content_widgets WHERE title = ?',
);
$existing->execute([$widgetTitle]);
check(
    (int) $existing->fetchColumn() === 0,
    'Token-owned widget title already exists.',
);
$baselineHash = $readWidgetHash();
$removeOwnedWidget = static function () use ($pdo, $widgetTitle): void {
    $count = $pdo->prepare(
        'SELECT COUNT(*) FROM public_content_widgets WHERE title = ?',
    );
    $count->execute([$widgetTitle]);
    $ownedRows = (int) $count->fetchColumn();
    check($ownedRows <= 1, 'More than one token-owned widget row exists.');
    if ($ownedRows === 1) {
        $delete = $pdo->prepare(
            'DELETE FROM public_content_widgets WHERE title = ?',
        );
        $delete->execute([$widgetTitle]);
        check(
            $delete->rowCount() === 1,
            'Token-owned widget cleanup did not remove exactly one row.',
        );
    }
};
$browserExitCode = 1;
try {
    $process = proc_open(
        [
            (string) (getenv('GALLERY_WORKFLOW_NODE') ?: 'node'),
            __DIR__ . '/public_widget_persistence_browser.mjs',
            $directory,
            $token,
            $browser,
        ],
        [
            0 => ['pipe', 'r'],
            1 => STDOUT,
            2 => ['file', $directory . '/node-widget.log', 'w'],
        ],
        $pipes,
        dirname(__DIR__),
        null,
        ['bypass_shell' => true],
    );
    check(
        is_resource($process),
        'Widget persistence browser runner unavailable.',
    );
    fclose($pipes[0]);
    $browserExitCode = proc_close($process);
    if ($browserExitCode !== 0) {
        $artifactDirectory = $preserveBrowserEvidence();
        $resultPath = $directory . DIRECTORY_SEPARATOR
            . 'browser-widget-result.json';
        $childResult = is_file($resultPath) && !is_link($resultPath)
            ? json_decode((string) file_get_contents($resultPath), true)
            : null;
        if (
            is_array($childResult)
            && is_string($childResult['stage'] ?? null)
            && preg_match('/^[a-zA-Z0-9 ._-]+$/D', $childResult['stage']) === 1
            && strlen($childResult['stage'])
                <= PUBLIC_WIDGET_BROWSER_RESULT_STAGE_MAX_CHARACTERS
            && is_string($childResult['detail'] ?? '')
            && preg_match('/^[a-zA-Z0-9 ._-]*$/D', $childResult['detail']) === 1
            && strlen($childResult['detail'])
                <= PUBLIC_WIDGET_BROWSER_RESULT_DETAIL_MAX_CHARACTERS
        ) {
            echo 'Widget browser child diagnostic: ' . $childResult['stage']
                . ($childResult['detail'] !== ''
                    ? ' ' . $childResult['detail'] : '') . "\n";
        }
        echo 'Widget browser evidence retained: ' . $artifactDirectory . "\n";
    }
} finally {
    $removeOwnedWidget();
    check(
        $readWidgetHash() === $baselineHash,
        'Widget persistence journey did not restore the exact initial widget state.',
    );
}
check($browserExitCode === 0, 'Widget persistence browser journey failed.');
echo "PASS public widget persistence browser and exact fixture cleanup\n";
