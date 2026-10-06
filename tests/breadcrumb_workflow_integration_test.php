<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/breadcrumb_workflow_integration_test.php
 * Module Type: Regression Test
 * Purpose: Verify breadcrumb style persistence through real Admin and public workflows.
 * Responsibilities:
 *   - Exercise Theme and physical-gallery settings against the disposable workflow fixture.
 *   - Verify inheritance, side-panel completion, validation atomicity, and Trash recovery.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once __DIR__ . '/support/gallery_workflow_http.php';

use GalleryWorkflow\Http;
use function GalleryWorkflow\check;
use function GalleryWorkflow\envelope;
use function GalleryWorkflow\fixtureDatabase;
use function GalleryWorkflow\row;
use function GalleryWorkflow\validateFixture;

if (!getenv('GALLERY_WORKFLOW_FIXTURE')) {
    $required = getenv('GALLERY_WORKFLOW_REQUIRED') === '1';
    echo ($required ? 'BLOCKED' : 'SKIP') . " gallery workflow breadcrumb persistence requires disposable runner\n";
    exit($required ? 1 : 0);
}

/** Read successful controls from one rendered form as PHP POST fields.
 *
 * @param string $html Complete HTML response from the disposable application.
 * @param string $formId Exact form id to serialize.
 * @return array<string,mixed> Form field names and current successful values.
 */
function breadcrumb_workflow_form_fields(string $html, string $formId): array
{
    $document = new DOMDocument();
    @$document->loadHTML($html);
    $xpath = new DOMXPath($document);
    $forms = $xpath->query('//form[@id="' . addslashes($formId) . '"]');
    $form = $forms?->item(0);
    check($form instanceof DOMElement, 'Rendered workflow form was missing.');
    $fields = [];
    foreach ($xpath->query('.//input | .//textarea | .//select', $form) ?: [] as $control) {
        if (!$control instanceof DOMElement || $control->hasAttribute('disabled')) {
            continue;
        }
        $name = $control->getAttribute('name');
        if ($name === '') {
            continue;
        }
        $type = strtolower($control->getAttribute('type'));
        if ($control->tagName === 'input' && in_array($type, ['button', 'submit', 'reset', 'image', 'file'], true)) {
            continue;
        }
        if ($control->tagName === 'input' && in_array($type, ['checkbox', 'radio'], true) && !$control->hasAttribute('checked')) {
            continue;
        }
        $value = $control->getAttribute('value');
        if ($control->tagName === 'textarea') {
            $value = $control->textContent;
        } elseif ($control->tagName === 'select') {
            $values = [];
            foreach ($control->getElementsByTagName('option') as $option) {
                if ($option->hasAttribute('selected')) {
                    $values[] = $option->getAttribute('value');
                }
            }
            if ($values === [] && !$control->hasAttribute('multiple')) {
                $first = $control->getElementsByTagName('option')->item(0);
                if ($first instanceof DOMElement) {
                    $values[] = $first->getAttribute('value');
                }
            }
            if ($control->hasAttribute('multiple')) {
                $value = $values;
            } else {
                $value = $values[0] ?? '';
            }
        }
        if (str_ends_with($name, '[]')) {
            $name = substr($name, 0, -2);
            $fields[$name] ??= [];
            foreach (is_array($value) ? $value : [$value] as $item) {
                $fields[$name][] = $item;
            }
        } else {
            $fields[$name] = $value;
        }
    }
    return $fields;
}

/** Assert that one rendered physical gallery uses the expected breadcrumb modifier.
 *
 * @param Http $client Authenticated or anonymous fixture client.
 * @param string $publicPath Canonical public path of the physical gallery.
 * @param string $style Stable registered breadcrumb style identifier.
 * @return void Fails when the public page or its breadcrumb class differs.
 */
function breadcrumb_workflow_assert_public_style(Http $client, string $publicPath, string $style): void
{
    $response = $client->request('/index.php?page=gallery&public_path=' . rawurlencode($publicPath));
    check($response['status'] === 200, 'Public gallery page did not render.');
    $document = new DOMDocument();
    @$document->loadHTML($response['body']);
    $nodes = (new DOMXPath($document))->query('//nav[contains(concat(" ", normalize-space(@class), " "), " breadcrumbs ")]');
    $nav = $nodes?->item(0);
    check($nav instanceof DOMElement && str_contains(' ' . $nav->getAttribute('class') . ' ', ' breadcrumbs--' . $style . ' '),
        'Public gallery breadcrumb style did not match the persisted effective style.');
}

$stage = 'fixture validation';
$admin = null;
$pdo = null;
$galleryId = 0;
$ownedFolderPath = '';
$trashToken = '';
$csrf = '';
$initialSettings = [];
$exitStatus = 0;
try {
    $token = (string) getenv('GALLERY_WORKFLOW_TOKEN');
    $directory = validateFixture((string) getenv('GALLERY_WORKFLOW_FIXTURE'), $token);
    $pdo = fixtureDatabase($directory, $token);
    $seed = json_decode((string) file_get_contents($directory . '/seed.json'), true, 512, JSON_THROW_ON_ERROR);
    $origin = json_decode((string) file_get_contents($directory . '/endpoint.json'), true, 512, JSON_THROW_ON_ERROR)['url'];
    $admin = new Http($origin);
    $anonymous = new Http($origin);
    $csrf = $admin->login($seed);
    $styleKey = 'gallery_breadcrumb_style.';
    $initialSettings = $pdo->query('SELECT setting_key, setting_value, updated_at FROM app_settings')->fetchAll(PDO::FETCH_ASSOC);

    $stage = 'Theme breadcrumb style save';
    $themePage = $admin->request('/index.php?page=admin_theme');
    check($themePage['status'] === 200, 'Theme editor did not render.');
    $themeFields = breadcrumb_workflow_form_fields($themePage['body'], 'admin-theme-form');
    $themeFields['csrf_token'] = $csrf;
    $themeFields['theme_controls_changed'] = '0';
    $themeFields['theme_breadcrumb_style'] = 'gradient';
    check($admin->request('/index.php?page=admin_theme', $themeFields)['status'] === 302, 'Theme style save did not use the normal form response.');
    check((string) row($pdo, 'SELECT setting_value FROM app_settings WHERE setting_key = ?', ['theme_breadcrumb_style'])['setting_value'] === 'gradient',
        'Theme breadcrumb style was not persisted.');

    $stage = 'create isolated physical gallery';
    $createRoute = '/index.php?page=admin_new_gallery&panel=1';
    $createFields = [
        'csrf_token' => $csrf,
        'title' => 'Breadcrumb workflow fixture',
        'folder_name' => 'breadcrumb-workflow-' . substr($token, 0, 8),
        'parent_id' => (string) $seed['root_id'],
        'visibility' => 'public',
        'panel' => '1',
        'operation_key' => $admin->operationKey($createRoute),
    ];
    $created = envelope($admin->request('/index.php?page=admin_new_gallery', $createFields, true), 'Breadcrumb gallery create');
    $galleryId = (int) ($created['gallery_id'] ?? 0);
    check($galleryId > 0, 'Fixture gallery identity missing.');
    $gallery = row($pdo, 'SELECT * FROM galleries WHERE id = ?', [$galleryId]);
    $ownedFolderPath = (string) ($gallery['folder_path'] ?? '');
    $publicPath = trim((string) ($gallery['url_path'] ?? '')) ?: trim((string) ($gallery['slug'] ?? ''));
    if ($publicPath === '') {
        $publicPath = $ownedFolderPath;
    }
    check($gallery !== [] && row($pdo, 'SELECT setting_key FROM app_settings WHERE setting_key = ?', [$styleKey . $galleryId]) === [],
        'New gallery unexpectedly has a stored style override.');

    $stage = 'inheritance follows Theme changes';
    breadcrumb_workflow_assert_public_style($anonymous, $publicPath, 'gradient');
    $editorRoute = '/index.php?page=admin_edit_gallery&id=' . $galleryId . '&tab=admin-edit-display&panel=1';
    $editor = $admin->request($editorRoute);
    check($editor['status'] === 200, 'Gallery display editor did not render.');
    $editorDocument = new DOMDocument();
    @$editorDocument->loadHTML($editor['body']);
    $stylePicker = (new DOMXPath($editorDocument))->query('//input[@type="radio" and @name="gallery_breadcrumb_style"]');
    check($stylePicker !== false && $stylePicker->length === 10, 'Gallery breadcrumb radio-card picker did not render inherit and all nine styles.');
    // Resolve the shared editor form by its stable class because it intentionally has no id.
    $formNode = (new DOMXPath($editorDocument))->query('//form[contains(concat(" ", normalize-space(@class), " "), " admin-edit-gallery-form ")]')->item(0);
    check($formNode instanceof DOMElement, 'Gallery editor form was missing.');
    $editorFields = breadcrumb_workflow_form_node_fields($editorDocument, $formNode);
    check(($editorFields['gallery_breadcrumb_style'] ?? null) === 'inherit', 'Workflow form serialization must include only the checked breadcrumb radio.');
    $editorFields['gallery_breadcrumb_style'] = 'inherit';
    $editorFields['return_tab'] = 'admin-edit-display';
    $savedInherit = envelope($admin->request('/index.php?page=admin_edit_gallery&id=' . $galleryId . '&panel=1', $editorFields, true), 'Gallery inherit save');
    check(($savedInherit['panel']['workflow'] ?? '') === 'gallery-edit' && ($savedInherit['panel']['keep_open'] ?? false) === true,
        'Gallery editor save did not retain its canonical in-place panel metadata.');
    breadcrumb_workflow_assert_public_style($anonymous, $publicPath, 'gradient');

    $stage = 'Theme change updates inherited gallery';
    $themePage = $admin->request('/index.php?page=admin_theme');
    $themeFields = breadcrumb_workflow_form_fields($themePage['body'], 'admin-theme-form');
    $themeFields['csrf_token'] = $csrf;
    $themeFields['theme_controls_changed'] = '0';
    $themeFields['theme_breadcrumb_style'] = 'tiles';
    check($admin->request('/index.php?page=admin_theme', $themeFields)['status'] === 302, 'Second Theme style save failed.');
    breadcrumb_workflow_assert_public_style($anonymous, $publicPath, 'tiles');

    $stage = 'explicit override and rerendered panel value';
    $editor = $admin->request($editorRoute);
    $editorDocument = new DOMDocument();
    @$editorDocument->loadHTML($editor['body']);
    $formNode = (new DOMXPath($editorDocument))->query('//form[contains(concat(" ", normalize-space(@class), " "), " admin-edit-gallery-form ")]')->item(0);
    check($formNode instanceof DOMElement, 'Gallery editor form disappeared after save.');
    $editorFields = breadcrumb_workflow_form_node_fields($editorDocument, $formNode);
    $editorFields['gallery_breadcrumb_style'] = 'ribbon';
    $editorFields['return_tab'] = 'admin-edit-display';
    $savedOverride = envelope($admin->request('/index.php?page=admin_edit_gallery&id=' . $galleryId . '&panel=1', $editorFields, true), 'Gallery override save');
    check(($savedOverride['panel']['workflow'] ?? '') === 'gallery-edit' && ($savedOverride['panel']['keep_open'] ?? false) === true,
        'Gallery override response lost its canonical panel metadata.');
    check((string) row($pdo, 'SELECT setting_value FROM app_settings WHERE setting_key = ?', [$styleKey . $galleryId])['setting_value'] === 'ribbon',
        'Per-gallery override was not persisted.');
    $galleryFolder = $directory . '/galleries/' . (string) $gallery['folder_path'];
    $sidecar = json_decode((string) file_get_contents($galleryFolder . '/gallery.json'), true, 512, JSON_THROW_ON_ERROR);
    check(($sidecar['breadcrumb_style'] ?? '') === 'ribbon', 'Gallery sidecar did not retain the explicit breadcrumb override.');
    $rerendered = $admin->request($editorRoute);
    $rerenderedDocument = new DOMDocument();
    @$rerenderedDocument->loadHTML($rerendered['body']);
    $rerenderedSelected = (new DOMXPath($rerenderedDocument))->query('//input[@type="radio" and @name="gallery_breadcrumb_style" and @value="ribbon" and @checked]')->item(0);
    check($rerenderedSelected instanceof DOMElement, 'Saved gallery style was not checked after the panel rerender.');
    breadcrumb_workflow_assert_public_style($anonymous, $publicPath, 'ribbon');

    $stage = 'explicit override remains fixed when Theme changes';
    $themePage = $admin->request('/index.php?page=admin_theme');
    $themeFields = breadcrumb_workflow_form_fields($themePage['body'], 'admin-theme-form');
    $themeFields['csrf_token'] = $csrf;
    $themeFields['theme_controls_changed'] = '0';
    $themeFields['theme_breadcrumb_style'] = 'chevron';
    check($admin->request('/index.php?page=admin_theme', $themeFields)['status'] === 302, 'Third Theme style save failed.');
    breadcrumb_workflow_assert_public_style($anonymous, $publicPath, 'ribbon');

    $stage = 'invalid style is atomic';
    $beforeGallery = row($pdo, 'SELECT * FROM galleries WHERE id = ?', [$galleryId]);
    $beforeStyle = row($pdo, 'SELECT setting_value FROM app_settings WHERE setting_key = ?', [$styleKey . $galleryId]);
    $editor = $admin->request($editorRoute);
    $editorDocument = new DOMDocument();
    @$editorDocument->loadHTML($editor['body']);
    $formNode = (new DOMXPath($editorDocument))->query('//form[contains(concat(" ", normalize-space(@class), " "), " admin-edit-gallery-form ")]')->item(0);
    check($formNode instanceof DOMElement, 'Gallery editor form missing for invalid-input check.');
    $invalidFields = breadcrumb_workflow_form_node_fields($editorDocument, $formNode);
    $invalidFields['title'] = 'Must remain unchanged after rejected breadcrumb input';
    $invalidFields['gallery_breadcrumb_style'] = 'not-a-registered-style';
    $invalidFields['return_tab'] = 'admin-edit-display';
    $invalid = $admin->request('/index.php?page=admin_edit_gallery&id=' . $galleryId . '&panel=1', $invalidFields, true);
    check($invalid['status'] >= 400 && $invalid['status'] < 500 && is_array($invalid['json']) && empty($invalid['json']['ok']),
        'Invalid style did not return a client error.');
    check(row($pdo, 'SELECT * FROM galleries WHERE id = ?', [$galleryId]) === $beforeGallery
        && row($pdo, 'SELECT setting_value FROM app_settings WHERE setting_key = ?', [$styleKey . $galleryId]) === $beforeStyle,
        'Rejected style input partially changed the gallery or its override.');

    $stage = 'Trash snapshot and restore preserve override';
    check($admin->request('/index.php?page=admin_bulk_galleries', [
        'csrf_token' => $csrf, 'gallery_ids' => [$galleryId], 'action' => 'delete',
    ])['status'] === 302, 'Gallery delete did not complete through its direct-page fallback.');
    $trash = row($pdo, "SELECT * FROM gallery_trash_entries WHERE status = 'trashed' ORDER BY id DESC LIMIT 1");
    $trashToken = (string) ($trash['trash_token'] ?? '');
    check($trashToken !== '', 'Gallery Trash record did not contain a restore token.');
    $snapshot = json_decode((string) ($trash['snapshot_json'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
    check(($snapshot['galleries'][0]['breadcrumb_style'] ?? '') === 'ribbon', 'Trash snapshot did not retain the gallery breadcrumb override.');
    check(row($pdo, 'SELECT setting_value FROM app_settings WHERE setting_key = ?', [$styleKey . $galleryId]) === [],
        'Deleted gallery retained its per-gallery setting key.');
    envelope($admin->request('/index.php?page=admin_trash_restore', ['csrf_token' => $csrf, 'trash_token' => $trashToken], true), 'Gallery restore');
    $restored = row($pdo, 'SELECT id FROM galleries WHERE folder_path = ?', [$gallery['folder_path']]);
    check($restored !== [], 'Restored gallery row was missing.');
    $restoredId = (int) $restored['id'];
    check((string) row($pdo, 'SELECT setting_value FROM app_settings WHERE setting_key = ?', [$styleKey . $restoredId])['setting_value'] === 'ribbon',
        'Trash restore did not restore the explicit breadcrumb style.');
    breadcrumb_workflow_assert_public_style($anonymous, $publicPath, 'ribbon');
    echo "PASS gallery workflow breadcrumb Theme inheritance panel persistence validation and Trash restore\n";
} catch (Throwable $exception) {
    $detail = get_class($exception) === RuntimeException::class ? $exception->getMessage()
        : basename($exception->getFile()) . ' line ' . $exception->getLine();
    fwrite(STDERR, 'FAIL gallery workflow breadcrumb ' . $stage . ': ' . $detail . "\n");
    $exitStatus = 1;
} finally {
    if ($pdo instanceof PDO) {
        try {
            // This test owns only the physical gallery it created, identified by its returned id.
            $ownedGallery = $ownedFolderPath !== '' ? row($pdo, 'SELECT * FROM galleries WHERE folder_path = ?', [$ownedFolderPath]) : [];
            if ($ownedGallery !== [] && $admin instanceof Http && $csrf !== '') {
                $admin->request('/index.php?page=admin_bulk_galleries', [
                    'csrf_token' => $csrf, 'gallery_ids' => [(int) $ownedGallery['id']], 'action' => 'delete',
                ], true);
            }
            if ($ownedFolderPath !== '' && $admin instanceof Http && $csrf !== '') {
                $ownedTrash = row($pdo, "SELECT trash_token FROM gallery_trash_entries WHERE original_folder_path = ? AND status = 'trashed' ORDER BY id DESC LIMIT 1", [$ownedFolderPath]);
                if ($ownedTrash !== []) {
                    $admin->request('/index.php?page=admin_trash_purge', [
                        'csrf_token' => $csrf, 'trash_token' => (string) $ownedTrash['trash_token'],
                    ], true);
                }
            }
        } catch (Throwable) {
            // A failed cleanup must not hide the workflow assertion; the runner still owns fixture disposal.
        }
        if ($initialSettings !== []) {
            try {
                // The full Theme form updates several app settings, so restore the exact fixture baseline.
                $pdo->exec('DELETE FROM app_settings');
                $restoreSetting = $pdo->prepare('INSERT INTO app_settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?)');
                foreach ($initialSettings as $setting) {
                    $restoreSetting->execute([$setting['setting_key'], $setting['setting_value'], $setting['updated_at']]);
                }
            } catch (Throwable) {
                // The disposable fixture runner remains responsible for final database teardown.
            }
        }
    }
}
exit($exitStatus);

/** Serialize successful controls inside one selected DOM form.
 *
 * @param DOMDocument $document Parsed response document.
 * @param DOMElement $form Form element whose controls should be submitted.
 * @return array<string,mixed> POST fields using PHP-compatible array names.
 */
function breadcrumb_workflow_form_node_fields(DOMDocument $document, DOMElement $form): array
{
    $fields = [];
    $xpath = new DOMXPath($document);
    foreach ($xpath->query('.//input | .//textarea | .//select', $form) ?: [] as $control) {
        if (!$control instanceof DOMElement || $control->hasAttribute('disabled')) {
            continue;
        }
        $name = $control->getAttribute('name');
        if ($name === '') {
            continue;
        }
        $type = strtolower($control->getAttribute('type'));
        if ($control->tagName === 'input' && in_array($type, ['button', 'submit', 'reset', 'image', 'file'], true)) {
            continue;
        }
        if ($control->tagName === 'input' && in_array($type, ['checkbox', 'radio'], true) && !$control->hasAttribute('checked')) {
            continue;
        }
        $value = $control->getAttribute('value');
        if ($control->tagName === 'textarea') {
            $value = $control->textContent;
        } elseif ($control->tagName === 'select') {
            $values = [];
            foreach ($control->getElementsByTagName('option') as $option) {
                if ($option->hasAttribute('selected')) {
                    $values[] = $option->getAttribute('value');
                }
            }
            if ($values === [] && !$control->hasAttribute('multiple')) {
                $first = $control->getElementsByTagName('option')->item(0);
                if ($first instanceof DOMElement) {
                    $values[] = $first->getAttribute('value');
                }
            }
            $value = $control->hasAttribute('multiple') ? $values : ($values[0] ?? '');
        }
        if (str_ends_with($name, '[]')) {
            $name = substr($name, 0, -2);
            $fields[$name] ??= [];
            foreach (is_array($value) ? $value : [$value] as $item) {
                $fields[$name][] = $item;
            }
        } else {
            $fields[$name] = $value;
        }
    }
    return $fields;
}
