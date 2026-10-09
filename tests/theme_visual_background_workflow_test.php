<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/theme_visual_background_workflow_test.php
 * Module Type: Disposable Workflow Regression Test
 * Purpose: Exercise real HTTP composite visual CSS saves with global Theme image replacement and removal.
 * Responsibilities:
 *   - Verify one explicit Save activates CSS and a global Theme image replacement or removal together.
 *   - Verify stale revisions preserve durable files, fallback mode and gallery cover metadata.
 *   - Verify Replace without a selected file leaves the saved CSS and Theme assets untouched.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once __DIR__ . '/support/gallery_workflow_http.php';

use GalleryWorkflow\Http;
use function GalleryWorkflow\check;
use function GalleryWorkflow\fixtureDatabase;
use function GalleryWorkflow\validateFixture;

/**
 * Capture the visual editor's owned settings and durable asset bytes.
 *
 * @param \PDO $pdo PDO connection to the explicitly owned workflow database.
 * @param string $root Validated disposable application-copy root.
 * @return array{settings:array<string,array{setting_value:string,updated_at:string}|null>,galleries:list<array{id:int,cover_image_id:int|null,cover_image_path:string|null,background_source:string|null,updated_at:string,edit_revision:int}>,css:?array{contents:string,mode:int},css_lock:?array{contents:string,mode:int},background_directory_exists:bool,background_files:array<string,array{contents:string,mode:int}>} Exact restorable Theme, gallery cover/background metadata, CSS and background file state.
 */
function themeVisualBackgroundWorkflowSnapshot(\PDO $pdo, string $root): array
{
    $keys = [
        'theme_background_path', 'theme_background_original_path', 'theme_background_optimized_path',
        'theme_background_source', 'theme_background_opacity', 'theme_background_optimized_max_side',
    ];
    $settings = [];
    $query = $pdo->prepare('SELECT setting_value, updated_at FROM app_settings WHERE setting_key = ?');
    foreach ($keys as $key) {
        $query->execute([$key]);
        $row = $query->fetch(\PDO::FETCH_ASSOC);
        $settings[$key] = is_array($row)
            ? ['setting_value' => (string) $row['setting_value'], 'updated_at' => (string) $row['updated_at']]
            : null;
    }

    $galleryQuery = $pdo->query('SELECT id, cover_image_id, cover_image_path, background_source, updated_at, edit_revision FROM galleries ORDER BY id');
    $galleries = [];
    foreach ($galleryQuery->fetchAll(\PDO::FETCH_ASSOC) as $row) {
        $galleries[] = [
            'id' => (int) $row['id'],
            'cover_image_id' => $row['cover_image_id'] === null ? null : (int) $row['cover_image_id'],
            'cover_image_path' => $row['cover_image_path'] === null ? null : (string) $row['cover_image_path'],
            'background_source' => $row['background_source'] === null ? null : (string) $row['background_source'],
            'updated_at' => (string) $row['updated_at'],
            'edit_revision' => (int) $row['edit_revision'],
        ];
    }

    $cssPath = $root . '/public/assets/custom-overrides.css';
    clearstatcache(true, $cssPath);
    $css = is_file($cssPath)
        ? ['contents' => (string) file_get_contents($cssPath), 'mode' => (int) (fileperms($cssPath) & 0777)]
        : null;
    $cssLockPath = $root . '/public/assets/.custom-overrides.lock';
    $cssLock = is_file($cssLockPath)
        ? ['contents' => (string) file_get_contents($cssLockPath), 'mode' => (int) (fileperms($cssLockPath) & 0777)]
        : null;
    $backgroundDirectory = $root . '/cache/theme-background';
    $backgroundDirectoryExists = is_dir($backgroundDirectory);
    $backgroundFiles = [];
    if ($backgroundDirectoryExists) {
        foreach (new DirectoryIterator($backgroundDirectory) as $entry) {
            if ($entry->isDot()) {
                continue;
            }
            check(!$entry->isLink() && $entry->isFile(), 'Theme background fixture storage contains an unexpected entry.');
            if ($entry->getFilename() === '.theme-background.lock') {
                continue;
            }
            $path = $entry->getPathname();
            $backgroundFiles[$entry->getFilename()] = [
                'contents' => (string) file_get_contents($path),
                'mode' => (int) (fileperms($path) & 0777),
            ];
        }
        ksort($backgroundFiles, SORT_STRING);
    }
    return [
        'settings' => $settings,
        'galleries' => $galleries,
        'css' => $css,
        'css_lock' => $cssLock,
        'background_directory_exists' => $backgroundDirectoryExists,
        'background_files' => $backgroundFiles,
    ];
}

/**
 * Restore the visual editor's settings and durable files after the isolated HTTP assertions.
 *
 * @param \PDO $pdo PDO connection to the explicitly owned workflow database.
 * @param string $root Validated disposable application-copy root.
 * @param array{settings:array<string,array{setting_value:string,updated_at:string}|null>,galleries:list<array{id:int,cover_image_id:int|null,cover_image_path:string|null,background_source:string|null,updated_at:string,edit_revision:int}>,css:?array{contents:string,mode:int},css_lock:?array{contents:string,mode:int},background_directory_exists:bool,background_files:array<string,array{contents:string,mode:int}>} $snapshot Previously captured state from themeVisualBackgroundWorkflowSnapshot().
 * @return void Restores the captured Theme settings, gallery cover metadata, manual override stylesheet and Theme background storage.
 */
function themeVisualBackgroundWorkflowRestore(\PDO $pdo, string $root, array $snapshot): void
{
    $pdo->beginTransaction();
    try {
        $delete = $pdo->prepare('DELETE FROM app_settings WHERE setting_key = ?');
        $insert = $pdo->prepare('INSERT INTO app_settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?)');
        foreach ($snapshot['settings'] as $key => $row) {
            $delete->execute([$key]);
            if ($row !== null) {
                $insert->execute([$key, $row['setting_value'], $row['updated_at']]);
            }
        }
        $restoreGallery = $pdo->prepare('UPDATE galleries SET cover_image_id = ?, cover_image_path = ?, background_source = ?, updated_at = ?, edit_revision = ? WHERE id = ?');
        foreach ($snapshot['galleries'] as $gallery) {
            $restoreGallery->execute([
                $gallery['cover_image_id'],
                $gallery['cover_image_path'],
                $gallery['background_source'],
                $gallery['updated_at'],
                $gallery['edit_revision'],
                $gallery['id'],
            ]);
        }
        $pdo->commit();
    } catch (\Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    $cssPath = $root . '/public/assets/custom-overrides.css';
    if ($snapshot['css'] === null) {
        if (is_file($cssPath)) {
            check(unlink($cssPath), 'Could not remove the test-installed manual CSS file.');
        }
    } else {
        check(file_put_contents($cssPath, $snapshot['css']['contents']) === strlen($snapshot['css']['contents']),
            'Could not restore the previous manual CSS file.');
        chmod($cssPath, $snapshot['css']['mode']);
    }
    $cssLockPath = $root . '/public/assets/.custom-overrides.lock';
    if ($snapshot['css_lock'] === null) {
        if (is_file($cssLockPath)) {
            check(unlink($cssLockPath), 'Could not remove the test-created manual CSS lock file.');
        }
    } else {
        check(file_put_contents($cssLockPath, $snapshot['css_lock']['contents']) === strlen($snapshot['css_lock']['contents']),
            'Could not restore the previous manual CSS lock file.');
        chmod($cssLockPath, $snapshot['css_lock']['mode']);
    }

    $backgroundDirectory = $root . '/cache/theme-background';
    if (!is_dir($backgroundDirectory)) {
        check(mkdir($backgroundDirectory, 0775, true), 'Could not recreate Theme background fixture storage.');
    }
    foreach (new DirectoryIterator($backgroundDirectory) as $entry) {
        if ($entry->isDot()) {
            continue;
        }
        check(!$entry->isLink() && $entry->isFile(), 'Refusing to restore over an unexpected Theme background entry.');
        check(unlink($entry->getPathname()), 'Could not remove a test-installed Theme background asset.');
    }
    foreach ($snapshot['background_files'] as $filename => $file) {
        check(basename($filename) === $filename, 'Refusing an unsafe Theme background snapshot name.');
        $path = $backgroundDirectory . '/' . $filename;
        check(file_put_contents($path, $file['contents']) === strlen($file['contents']), 'Could not restore a prior Theme background asset.');
        chmod($path, $file['mode']);
    }
    if (!$snapshot['background_directory_exists'] && $snapshot['background_files'] === []) {
        @rmdir($backgroundDirectory);
    }
}

/**
 * Require the editor page to expose current revisions and the shared CSRF token.
 *
 * @param string $html HTML returned by the authenticated Theme editor.
 * @return array{csrf:string,css_text:string,css_revision:string,background_revision:string,background_url:string} Values read from actual server-rendered form controls and the editor's prepared public URL.
 */
function themeVisualBackgroundWorkflowFields(string $html): array
{
    $document = new DOMDocument();
    check(@$document->loadHTML($html), 'The authenticated Theme editor response is not parseable HTML.');
    $xpath = new DOMXPath($document);
    $text = $xpath->query('//textarea[@name="css_override_text"]')->item(0);
    $cssRevision = $xpath->query('//input[@name="css_override_revision"]')->item(0);
    $backgroundRevision = $xpath->query('//input[@name="theme_background_revision"]')->item(0);
    $csrf = $xpath->query('//input[@name="csrf_token"]')->item(0);
    $editor = $xpath->query('//*[@data-css-override-editor]')->item(0);
    check($text instanceof DOMElement && $cssRevision instanceof DOMElement
        && $backgroundRevision instanceof DOMElement && $csrf instanceof DOMElement && $editor instanceof DOMElement,
        'The real Theme editor is missing a CSS, background-revision or CSRF form field.');
    return [
        'csrf' => $csrf->getAttribute('value'),
        'css_text' => $text->textContent,
        'css_revision' => $cssRevision->getAttribute('value'),
        'background_revision' => $backgroundRevision->getAttribute('value'),
        'background_url' => $editor->getAttribute('data-visual-editor-background-url'),
    ];
}

/**
 * Install a nondefault Theme gallery-fallback mode for this disposable workflow.
 *
 * @param \PDO $pdo Connection to the explicitly owned workflow database.
 * @param string $source Valid Theme gallery fallback mode supported by the service.
 * @return void Makes replacement/removal preservation observable; the outer snapshot restores the original row.
 */
function themeVisualBackgroundWorkflowSetThemeSource(\PDO $pdo, string $source): void
{
    check(in_array($source, ['upload', 'existing', 'collage'], true), 'Unexpected disposable Theme fallback mode.');
    $query = $pdo->prepare('SELECT setting_key FROM app_settings WHERE setting_key = ?');
    $query->execute(['theme_background_source']);
    if ($query->fetchColumn() !== false) {
        $save = $pdo->prepare('UPDATE app_settings SET setting_value = ?, updated_at = CURRENT_TIMESTAMP WHERE setting_key = ?');
        $save->execute([$source, 'theme_background_source']);
        return;
    }
    $save = $pdo->prepare('INSERT INTO app_settings (setting_key, setting_value, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)');
    $save->execute(['theme_background_source', $source]);
}

/**
 * Give one seeded gallery an existing-photo cover and a nondefault fallback selection.
 *
 * @param \PDO $pdo Connection to the explicitly owned workflow database.
 * @param int $galleryId Root gallery identifier from the workflow seed.
 * @return void Creates concrete cover metadata for the later no-mutation assertions; outer snapshot restores it.
 */
function themeVisualBackgroundWorkflowPrepareGalleryCover(\PDO $pdo, int $galleryId): void
{
    $imageQuery = $pdo->prepare('SELECT id FROM images WHERE gallery_id = ? ORDER BY id LIMIT 1');
    $imageQuery->execute([$galleryId]);
    $imageId = $imageQuery->fetchColumn();
    check($imageId !== false, 'The seeded gallery has no photo available for its disposable cover-preservation assertion.');
    $update = $pdo->prepare('UPDATE galleries SET cover_image_id = ?, background_source = ?, updated_at = CURRENT_TIMESTAMP, edit_revision = edit_revision + 1 WHERE id = ?');
    $update->execute([(int) $imageId, 'existing', $galleryId]);
}

/**
 * Submit one real multipart CSS/background save and return its decoded response.
 *
 * @param Http $admin Cookie-authenticated disposable workflow client.
 * @param string $route Existing Admin Theme route carrying the editor form.
 * @param array{csrf:string,css_text:string,css_revision:string,background_revision:string,background_url:string} $fields Actual server-rendered revisions and CSRF value.
 * @param string $cssText Complete CSS text submitted with the pending image.
 * @param string $cssRevision Expected saved CSS revision.
 * @param string $backgroundRevision Expected saved Theme background revision.
 * @param string $uploadPath Valid raster upload staged only in the disposable fixture directory.
 * @return array{status:int,body:string,headers:array<string,string>,json:mixed} Real multipart HTTP response.
 */
function themeVisualBackgroundWorkflowPost(Http $admin, string $route, array $fields, string $cssText,
    string $cssRevision, string $backgroundRevision, string $uploadPath): array
{
    return $admin->request($route, [
        'csrf_token' => $fields['csrf'],
        'css_override_action' => 'save',
        'css_override_text' => $cssText,
        'css_override_revision' => $cssRevision,
        'theme_background_revision' => $backgroundRevision,
        'theme_background_file' => new CURLFile($uploadPath, 'image/png', 'workflow-background.png'),
    ], true);
}

/**
 * Submit one explicit Theme-image operation with a CSS draft and caller-selected operation target/action.
 *
 * @param Http $admin Cookie-authenticated disposable workflow client.
 * @param string $route Existing Admin Theme route carrying the editor form.
 * @param array{csrf:string,css_text:string,css_revision:string,background_revision:string,background_url:string} $fields Actual server-rendered CSRF value and form values.
 * @param string $operation Explicit keep or remove operation for the global Theme image.
 * @param string $cssText Complete CSS draft submitted in the same Save.
 * @param string $cssRevision Expected saved CSS revision.
 * @param string $backgroundRevision Expected saved global Theme-image revision.
 * @param string $target Explicit operation target; defaults to the global Theme image.
 * @param string $action Save action; tests use unsupported values to verify fail-closed handling.
 * @return array{status:int,body:string,headers:array<string,string>,json:mixed} Real JSON HTTP response from the composite save route.
 */
function themeVisualBackgroundWorkflowOperationPost(Http $admin, string $route, array $fields,
    string $operation, string $cssText, string $cssRevision, string $backgroundRevision,
    string $target = 'theme', string $action = 'save'): array
{
    return $admin->request($route, [
        'csrf_token' => $fields['csrf'],
        'css_override_action' => $action,
        'css_override_text' => $cssText,
        'css_override_revision' => $cssRevision,
        'theme_background_revision' => $backgroundRevision,
        'theme_background_operation' => $operation,
        'theme_background_target' => $target,
    ], true);
}

/**
 * Submit explicit Replace metadata without an uploaded file part.
 * @param Http $admin Cookie-authenticated disposable workflow client.
 * @param string $route Existing Admin Theme route carrying the editor form.
 * @param array{csrf:string,css_text:string,css_revision:string,background_revision:string,background_url:string} $fields Actual server-rendered CSRF value and current form values.
 * @param string $cssText CSS draft which must not be published when its requested image is missing.
 * @param string $cssRevision Current expected CSS revision.
 * @param string $backgroundRevision Current expected Theme-image revision.
 * @return array{status:int,body:string,headers:array<string,string>,json:array{ok:bool,message:string,state:null,background:null}|null} Real response from a Replace request with no file field; the JSON member is the expected failure envelope or null when decoding fails or returns JSON null.
 */
function themeVisualBackgroundWorkflowMissingReplacementPost(Http $admin, string $route, array $fields,
    string $cssText, string $cssRevision, string $backgroundRevision): array
{
    return $admin->request($route, [
        'csrf_token' => $fields['csrf'],
        'css_override_action' => 'save',
        'css_override_text' => $cssText,
        'css_override_revision' => $cssRevision,
        'theme_background_revision' => $backgroundRevision,
        'theme_background_operation' => 'replace',
        'theme_background_target' => 'theme',
    ], true);
}

if (!getenv('GALLERY_WORKFLOW_FIXTURE')) {
    $required = getenv('GALLERY_WORKFLOW_REQUIRED') === '1';
    echo ($required ? 'BLOCKED' : 'SKIP') . " Theme visual background real HTTP coverage requires the disposable database runner\n";
    exit($required ? 1 : 0);
}

$stage = 'fixture validation';
$failed = false;
$snapshot = null;
$uploadPath = '';
try {
    $token = (string) getenv('GALLERY_WORKFLOW_TOKEN');
    $root = validateFixture((string) getenv('GALLERY_WORKFLOW_FIXTURE'), $token);
    $pdo = fixtureDatabase($root, $token);
    $seed = json_decode((string) file_get_contents($root . '/seed.json'), true, 512, JSON_THROW_ON_ERROR);
    $origin = json_decode((string) file_get_contents($root . '/endpoint.json'), true, 512, JSON_THROW_ON_ERROR)['url'];
    $admin = new Http($origin);
    $admin->login($seed);
    $route = '/index.php?page=admin_theme&css_editor=1';

    $stage = 'pre-save immutability and server-rendered revisions';
    $snapshot = themeVisualBackgroundWorkflowSnapshot($pdo, $root);
    themeVisualBackgroundWorkflowSetThemeSource($pdo, 'existing');
    themeVisualBackgroundWorkflowPrepareGalleryCover($pdo, (int) ($seed['root_id'] ?? 0));
    $fixtureBaseline = themeVisualBackgroundWorkflowSnapshot($pdo, $root);
    $editor = $admin->request($route);
    check($editor['status'] === 200, 'The real Admin Theme editor did not load.');
    $fields = themeVisualBackgroundWorkflowFields($editor['body']);
    $uploadPath = tempnam($root, 'visual-background-');
    check(is_string($uploadPath), 'Could not create a fixture-owned image source.');
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j3ioAAAAASUVORK5CYII=', true);
    check(is_string($png) && file_put_contents($uploadPath, $png) === strlen($png), 'Could not prepare the deterministic PNG upload source.');
    $afterPendingPreparation = themeVisualBackgroundWorkflowSnapshot($pdo, $root);
    check($afterPendingPreparation['settings'] === $fixtureBaseline['settings']
        && $afterPendingPreparation['galleries'] === $fixtureBaseline['galleries']
        && $afterPendingPreparation['css'] === $fixtureBaseline['css']
        && $afterPendingPreparation['background_files'] === $fixtureBaseline['background_files'],
        'Loading the editor or preparing a pending local image must not change CSS, Theme settings or stored background assets.');
    $seededRootGallery = array_values(array_filter($afterPendingPreparation['galleries'],
        static fn (array $gallery): bool => $gallery['id'] === (int) ($seed['root_id'] ?? 0)))[0] ?? null;
    check(count($afterPendingPreparation['galleries']) >= 2 && is_array($seededRootGallery)
        && $seededRootGallery['cover_image_id'] !== null && $seededRootGallery['background_source'] === 'existing',
        'The workflow seed did not provide concrete gallery cover and background fallback metadata for preservation checks.');

    $stage = 'explicit composite save';
    $nextCss = "/* issue 127 disposable composite save */\n.workflow-composite-save{color:rgb(1,2,3)}\n";
    $saved = themeVisualBackgroundWorkflowPost($admin, $route, $fields, $nextCss,
        $fields['css_revision'], $fields['background_revision'], $uploadPath);
    $result = $saved['json'];
    check($saved['status'] === 200 && is_array($result) && ($result['ok'] ?? false) === true
        && is_array($result['state'] ?? null) && is_array($result['background'] ?? null),
        'The explicit multipart CSS/background save did not return its successful JSON state.');
    $state = $result['state'];
    $background = $result['background'];
    check(($state['text'] ?? null) === $nextCss && is_string($state['revision'] ?? null)
        && is_string($state['url'] ?? null), 'The composite save response does not describe the saved CSS.');
    check(($background['available'] ?? false) === true && ($background['source'] ?? '') === 'existing'
        && is_string($background['revision'] ?? null) && is_string($background['url'] ?? null)
        && $background['url'] !== '' && $background['url'] !== $fields['background_url']
        && $background['revision'] !== $fields['background_revision']
        && ($background['operation'] ?? '') === 'replace' && ($background['target'] ?? '') === 'theme',
        'The composite save response does not describe a newly versioned global Theme image while preserving the gallery fallback mode.');
    $backgroundUrlQuery = [];
    parse_str((string) (parse_url($background['url'], PHP_URL_QUERY) ?: ''), $backgroundUrlQuery);
    check(isset($backgroundUrlQuery['v']) && (string) $backgroundUrlQuery['v'] !== '',
        'The uploaded background URL has no immutable-asset cache version.');

    $cssPath = $root . '/public/assets/custom-overrides.css';
    check(is_file($cssPath) && file_get_contents($cssPath) === $nextCss,
        'The explicit save did not activate the submitted CSS bytes.');
    $cssUrlQuery = [];
    parse_str((string) (parse_url($state['url'], PHP_URL_QUERY) ?: ''), $cssUrlQuery);
    check(($cssUrlQuery['v'] ?? '') === $state['revision'], 'The saved CSS URL does not carry its new content revision.');
    $settingRows = themeVisualBackgroundWorkflowSnapshot($pdo, $root);
    $persistedSettings = $settingRows['settings'];
    check(($persistedSettings['theme_background_source']['setting_value'] ?? '') === 'existing'
        && ($persistedSettings['theme_background_original_path']['setting_value'] ?? '') !== '',
        'The global Theme image upload changed the gallery fallback mode or failed to persist its image asset.');
    $relativeAssets = [
        $persistedSettings['theme_background_original_path']['setting_value'],
        $persistedSettings['theme_background_optimized_path']['setting_value'] ?? '',
    ];
    foreach ($relativeAssets as $relativeAsset) {
        if ($relativeAsset === '') {
            continue;
        }
        check(str_starts_with($relativeAsset, 'cache/theme-background/')
            && is_file($root . '/' . $relativeAsset), 'A persisted Theme background path does not resolve to its owned stored asset.');
    }
    $themeCss = $admin->request('/index.php?page=theme_css');
    check($themeCss['status'] === 200 && str_contains($themeCss['body'], $background['url']),
        'The real generated Theme stylesheet did not emit the saved immutable background cache URL.');
    $afterSave = themeVisualBackgroundWorkflowSnapshot($pdo, $root);

    $stage = 'stale CSS revision preserves uploaded candidate and active assets';
    $staleCss = themeVisualBackgroundWorkflowPost($admin, $route, $fields, $nextCss . "/* stale retry */\n",
        $fields['css_revision'], $background['revision'], $uploadPath);
    check($staleCss['status'] === 409 && is_array($staleCss['json'])
        && ($staleCss['json']['ok'] ?? true) === false && $staleCss['json']['state'] === null
        && $staleCss['json']['background'] === null,
        'A stale CSS revision must return a bounded conflict without authoritative replacement snapshots.');
    check(themeVisualBackgroundWorkflowSnapshot($pdo, $root) === $afterSave && is_file($uploadPath),
        'A stale CSS conflict changed saved files/settings or consumed the client-owned upload source.');

    $stage = 'stale background revision preserves uploaded candidate and active assets';
    $freshEditor = $admin->request($route);
    check($freshEditor['status'] === 200, 'The Theme editor did not reload after the successful composite save.');
    $freshFields = themeVisualBackgroundWorkflowFields($freshEditor['body']);
    $staleBackground = themeVisualBackgroundWorkflowPost($admin, $route, $freshFields, $nextCss . "/* stale background retry */\n",
        $state['revision'], $fields['background_revision'], $uploadPath);
    check($staleBackground['status'] === 409 && is_array($staleBackground['json'])
        && ($staleBackground['json']['ok'] ?? true) === false && $staleBackground['json']['state'] === null
        && $staleBackground['json']['background'] === null,
        'A stale background revision must return a bounded conflict without authoritative replacement snapshots.');
    check(themeVisualBackgroundWorkflowSnapshot($pdo, $root) === $afterSave && is_file($uploadPath),
        'A stale background conflict changed saved files/settings or consumed the client-owned upload source.');

    $stage = 'unsupported Theme image target and nonsave actions fail closed';
    foreach ([['target' => 'gallery', 'action' => 'save'], ['target' => 'theme', 'action' => 'reload']] as $invalidRequest) {
        $invalidOperation = themeVisualBackgroundWorkflowOperationPost($admin, $route, $freshFields,
            'remove', $nextCss, $state['revision'], $background['revision'],
            $invalidRequest['target'], $invalidRequest['action']);
        check($invalidOperation['status'] === 422 && is_array($invalidOperation['json'])
            && ($invalidOperation['json']['ok'] ?? true) === false && $invalidOperation['json']['state'] === null
            && $invalidOperation['json']['background'] === null,
            'An unsupported Theme image target or nonsave operation was not rejected without snapshots.');
        check(themeVisualBackgroundWorkflowSnapshot($pdo, $root) === $afterSave,
            'An unsupported Theme image request changed CSS, fallback, cover data or files.');
    }

    $stage = 'explicit Replace without a selected attachment preserves every published asset';
    $missingAttachment = themeVisualBackgroundWorkflowMissingReplacementPost($admin, $route, $freshFields,
        $nextCss . "/* missing replacement attachment */\n", $state['revision'], $background['revision']);
    check($missingAttachment['status'] === 422 && is_array($missingAttachment['json'])
        && ($missingAttachment['json']['ok'] ?? true) === false
        && is_string($missingAttachment['json']['message'] ?? null)
        && trim($missingAttachment['json']['message']) !== ''
        && $missingAttachment['json']['state'] === null
        && $missingAttachment['json']['background'] === null,
        'Explicit Replace without an uploaded attachment must return an actionable refusal without authoritative snapshots.');
    check(themeVisualBackgroundWorkflowSnapshot($pdo, $root) === $afterSave,
        'Explicit Replace without an uploaded attachment published CSS or changed Theme settings, gallery metadata or background assets.');

    $stage = 'stale CSS revision refuses Theme image removal without touching fallback or cover data';
    $staleCssRemoval = themeVisualBackgroundWorkflowOperationPost($admin, $route, $freshFields,
        'remove', $nextCss . "/* stale removal CSS */\n", $fields['css_revision'], $background['revision']);
    check($staleCssRemoval['status'] === 409 && is_array($staleCssRemoval['json'])
        && ($staleCssRemoval['json']['ok'] ?? true) === false && $staleCssRemoval['json']['state'] === null
        && $staleCssRemoval['json']['background'] === null,
        'A stale CSS revision must reject the composite Theme image removal without returning new snapshots.');
    check(themeVisualBackgroundWorkflowSnapshot($pdo, $root) === $afterSave,
        'A stale CSS removal changed Theme files, fallback or gallery cover metadata.');

    $stage = 'stale Theme image revision refuses removal without touching CSS or gallery metadata';
    $staleBackgroundRemoval = themeVisualBackgroundWorkflowOperationPost($admin, $route, $freshFields,
        'remove', $nextCss . "/* stale removal background */\n", $state['revision'], $fields['background_revision']);
    check($staleBackgroundRemoval['status'] === 409 && is_array($staleBackgroundRemoval['json'])
        && ($staleBackgroundRemoval['json']['ok'] ?? true) === false && $staleBackgroundRemoval['json']['state'] === null
        && $staleBackgroundRemoval['json']['background'] === null,
        'A stale Theme background revision must reject removal without returning new snapshots.');
    check(themeVisualBackgroundWorkflowSnapshot($pdo, $root) === $afterSave,
        'A stale Theme image removal changed CSS, fallback or gallery cover metadata.');

    $stage = 'explicit Theme image removal with fallback and gallery cover preservation';
    $removeCss = "/* issue 127 explicit removal */\n.workflow-after-remove{color:rgb(4,5,6)}\n";
    $removed = themeVisualBackgroundWorkflowOperationPost($admin, $route, $freshFields,
        'remove', $removeCss, $state['revision'], $background['revision']);
    check($removed['status'] === 200 && is_array($removed['json']) && ($removed['json']['ok'] ?? false) === true
        && is_array($removed['json']['state'] ?? null) && is_array($removed['json']['background'] ?? null),
        'The explicit composite Theme image removal did not return successful CSS and background snapshots.');
    $removedState = $removed['json']['state'];
    $removedBackground = $removed['json']['background'];
    check(($removedState['text'] ?? null) === $removeCss && file_get_contents($cssPath) === $removeCss
        && ($removedBackground['operation'] ?? '') === 'remove' && ($removedBackground['target'] ?? '') === 'theme'
        && ($removedBackground['available'] ?? true) === false && ($removedBackground['url'] ?? null) === ''
        && ($removedBackground['source'] ?? '') === 'existing',
        'Removal did not atomically save CSS, clear the global Theme image, and preserve the gallery fallback mode.');
    $afterRemoval = themeVisualBackgroundWorkflowSnapshot($pdo, $root);
    foreach (['theme_background_path', 'theme_background_original_path', 'theme_background_optimized_path'] as $key) {
        check(($afterRemoval['settings'][$key]['setting_value'] ?? '') === '',
            'Theme image removal left its global path setting populated: ' . $key);
    }
    foreach (['theme_background_source', 'theme_background_opacity', 'theme_background_optimized_max_side'] as $key) {
        check($afterRemoval['settings'][$key] === $afterSave['settings'][$key],
            'Theme image removal changed the unrelated global fallback or appearance setting: ' . $key);
    }
    check($afterRemoval['background_files'] === [] && $afterRemoval['galleries'] === $afterSave['galleries'],
        'Theme image removal retained owned image assets or changed gallery cover/background metadata.');

    $stage = 'idempotent no-image removal';
    $afterRemovalEditor = $admin->request($route);
    check($afterRemovalEditor['status'] === 200, 'The Theme editor did not reload after image removal.');
    $afterRemovalFields = themeVisualBackgroundWorkflowFields($afterRemovalEditor['body']);
    $idempotentRemoval = themeVisualBackgroundWorkflowOperationPost($admin, $route, $afterRemovalFields,
        'remove', $removeCss, $removedState['revision'], $removedBackground['revision']);
    check($idempotentRemoval['status'] === 200 && is_array($idempotentRemoval['json'])
        && ($idempotentRemoval['json']['ok'] ?? false) === true
        && ($idempotentRemoval['json']['state'] ?? null) === $removedState
        && themeVisualBackgroundWorkflowSnapshot($pdo, $root) === $afterRemoval,
        'Removing an already absent Theme image with current revisions was not a no-write idempotent save.');
    echo "PASS real HTTP composite replacement/removal, stale CSS/background revisions, fallback/cover preservation and idempotent removal\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL Theme visual background workflow ' . $stage . ' at ' . basename($exception->getFile()) . ' line ' . $exception->getLine() . "\n");
    $failed = true;
} finally {
    if (is_string($uploadPath) && $uploadPath !== '' && is_file($uploadPath)) {
        @unlink($uploadPath);
    }
    if (is_array($snapshot) && isset($pdo, $root)) {
        themeVisualBackgroundWorkflowRestore($pdo, $root, $snapshot);
    }
}
if ($failed) {
    exit(1);
}
