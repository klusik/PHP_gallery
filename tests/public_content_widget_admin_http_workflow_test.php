<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/public_content_widget_admin_http_workflow_test.php
 * Module Type: Disposable Workflow Regression Test
 * Purpose: Verify widget Admin authorization, preview, persistence and stale revisions through real HTTP.
 * Responsibilities:
 *   - Use only the explicitly owned disposable workflow application and database.
 *   - Exercise the actual Theme widget route with isolated administrator sessions.
 *   - Remove only the unique widget row created by this workflow.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once __DIR__ . '/support/gallery_workflow_http.php';

use GalleryWorkflow\Http;
use function GalleryWorkflow\check;
use function GalleryWorkflow\countRows;
use function GalleryWorkflow\fixtureDatabase;
use function GalleryWorkflow\row;
use function GalleryWorkflow\validateFixture;

if (!getenv('GALLERY_WORKFLOW_FIXTURE')) {
    $required = getenv('GALLERY_WORKFLOW_REQUIRED') === '1';
    echo ($required ? 'BLOCKED' : 'SKIP')
        . " public content widget Admin HTTP workflow requires the disposable workflow runner\n";
    exit($required ? 1 : 0);
}

$stage = 'fixture validation';
$createdIds = [];
$failureMessage = null;
$widgetMarker = bin2hex(random_bytes(12));
$ownedWidgetTitle = 'Widget HTTP workflow ' . $widgetMarker;
$draftContent = '**Widget HTTP draft** ' . $widgetMarker;
$forbiddenLinkDraft = $draftContent . "\n[Forbidden target](javascript:alert(1))";
try {
    $token = (string) getenv('GALLERY_WORKFLOW_TOKEN');
    $directory = validateFixture((string) getenv('GALLERY_WORKFLOW_FIXTURE'), $token);
    $pdo = fixtureDatabase($directory, $token);
    $seed = json_decode((string) file_get_contents($directory . '/seed.json'), true, 512, JSON_THROW_ON_ERROR);
    $origin = json_decode((string) file_get_contents($directory . '/endpoint.json'), true, 512, JSON_THROW_ON_ERROR)['url'];
    $widgetRoute = '/index.php?page=admin_theme&widgets=1';
    $initialCount = countRows($pdo, 'public_content_widgets');
    $createPayload = [
        'widget_id' => '', 'revision' => '0', 'widget_action' => 'create',
        'title' => $ownedWidgetTitle, 'content_md' => $draftContent,
        'status' => 'draft', 'page_scope' => 'home', 'placement_mode' => 'flow',
        'flow_slot' => 'home_after_grid', 'floating_anchor' => 'bottom-right',
        'x_permille' => '900', 'y_permille' => '900', 'width_px' => '320',
        'sort_order' => '0', 'mobile_fallback' => 'flow', 'appearance' => 'card',
        'source_language' => 'en',
    ];

    $stage = 'anonymous and invalid-CSRF denials';
    $anonymous = new Http($origin);
    $anonymousCreate = $anonymous->request($widgetRoute,
        array_replace($createPayload, ['csrf_token' => 'missing']), true);
    check($anonymousCreate['status'] === 401
        && is_array($anonymousCreate['json'])
        && ($anonymousCreate['json']['error_code'] ?? '') === 'auth.admin_required',
        'Anonymous widget creation did not fail at the Admin authorization boundary.');
    $anonymousPreview = $anonymous->request($widgetRoute, [
        'csrf_token' => 'missing', 'widget_action' => 'preview', 'widget_preview_json' => '1',
        'title' => 'Anonymous preview', 'content_md' => $draftContent,
    ], true);
    check($anonymousPreview['status'] === 401
        && is_array($anonymousPreview['json'])
        && ($anonymousPreview['json']['error_code'] ?? '') === 'auth.admin_required',
        'Anonymous widget preview did not fail at the Admin authorization boundary.');

    $admin = new Http($origin);
    $admin->login($seed);
    $formResponse = $admin->request($widgetRoute);
    check($formResponse['status'] === 200, 'Authenticated widget editor did not load.');
    $readEditor = static function (string $html): array {
        $document = new DOMDocument();
        $previousLibxmlState = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML($html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousLibxmlState);
        }
        check($loaded, 'Widget editor HTML could not be parsed.');
        $xpath = new DOMXPath($document);
        $form = $xpath->query('//form[contains(concat(" ", normalize-space(@class), " "), " public-widgets-form ")]')->item(0);
        check($form instanceof DOMElement, 'Actual widget editor form was not rendered.');
        $inputValue = static function (string $name) use ($xpath, $form): string {
            $input = $xpath->query('.//input[@name="' . $name . '"]', $form)->item(0);
            return $input instanceof DOMElement ? $input->getAttribute('value') : '';
        };
        $titleInput = $xpath->query('.//input[@name="title"]', $form)->item(0);
        $content = $xpath->query('.//textarea[@name="content_md"]', $form)->item(0);
        $error = $xpath->query('//*[@data-widget-error-field]')->item(0);
        $errorField = $error instanceof DOMElement ? $error->getAttribute('data-widget-error-field') : '';
        return [
            'csrf_token' => $inputValue('csrf_token'),
            'widget_id' => $inputValue('widget_id'),
            'revision' => $inputValue('revision'),
            'title' => $titleInput instanceof DOMElement ? $titleInput->getAttribute('value') : '',
            'content_md' => $content instanceof DOMElement ? $content->textContent : '',
            'error_field' => $errorField,
            'error_message' => $error instanceof DOMElement ? trim($error->textContent) : '',
            'revision_error' => $errorField === 'revision',
        ];
    };
    $editor = $readEditor($formResponse['body']);
    check($editor['csrf_token'] !== '', 'Actual widget form did not render its CSRF token.');
    check(countRows($pdo, 'public_content_widgets') === $initialCount,
        'Anonymous requests or editor loading changed widget persistence.');

    $stage = 'CSRF refusal and read-only Admin preview';
    $invalidCsrfCreate = $admin->request($widgetRoute,
        array_replace($createPayload, ['csrf_token' => 'invalid']), true);
    check($invalidCsrfCreate['status'] === 400
        && is_array($invalidCsrfCreate['json'])
        && ($invalidCsrfCreate['json']['error_code'] ?? '') === 'security.invalid_csrf',
        'Invalid-CSRF widget creation was not rejected.');
    $preview = $admin->request($widgetRoute, [
        'csrf_token' => 'invalid', 'widget_action' => 'preview',
        'widget_preview_json' => '1', 'widget_id' => '', 'title' => 'Preview draft',
        'content_md' => $draftContent, 'appearance' => 'card',
    ], true);
    check($preview['status'] === 400
        && is_array($preview['json'])
        && ($preview['json']['error_code'] ?? '') === 'security.invalid_csrf',
        'Invalid-CSRF widget preview was not rejected.');
    check(countRows($pdo, 'public_content_widgets') === $initialCount,
        'Rejected widget mutations or preview changed persistent rows.');

    $stage = 'unsafe link create refusal and draft retention';
    $unsafeCreate = $admin->request($widgetRoute, array_replace($createPayload, [
        'csrf_token' => $editor['csrf_token'], 'content_md' => $forbiddenLinkDraft,
    ]));
    check($unsafeCreate['status'] === 200,
        'Widget creation with a forbidden javascript: link was not returned to the editor.');
    $unsafeForm = $readEditor($unsafeCreate['body']);
    check($unsafeForm['title'] === $ownedWidgetTitle && $unsafeForm['content_md'] === $forbiddenLinkDraft,
        'Rejected unsafe-link creation did not preserve the submitted title and Markdown draft.');
    check($unsafeForm['error_field'] === 'content_md' && $unsafeForm['error_message'] !== '',
        'Rejected unsafe-link creation did not identify and describe the content_md field error.');
    check(countRows($pdo, 'public_content_widgets') === $initialCount,
        'Rejected unsafe-link creation changed persistent widget rows.');

    $preview = $admin->request($widgetRoute, [
        'csrf_token' => $editor['csrf_token'], 'widget_action' => 'preview',
        'widget_preview_json' => '1', 'widget_id' => '', 'title' => 'Preview draft',
        'content_md' => $draftContent, 'appearance' => 'card',
    ], true);
    check($preview['status'] === 200 && is_array($preview['json']) && ($preview['json']['ok'] ?? null) === true
        && str_contains((string) ($preview['json']['html'] ?? ''), '<strong>Widget HTTP draft</strong>')
        && str_contains(strtolower((string) ($preview['headers']['cache-control'] ?? '')), 'no-store'),
        'Authenticated widget Markdown preview was not rendered as a no-store response.');
    check(countRows($pdo, 'public_content_widgets') === $initialCount,
        'Authenticated widget preview persisted a draft.');

    $stage = 'create and reload persistence';
    $created = $admin->request($widgetRoute,
        array_replace($createPayload, ['csrf_token' => $editor['csrf_token']]));
    check($created['status'] === 302 && isset($created['headers']['location']),
        'Valid widget creation did not use post/redirect/get.');
    $locationQuery = (string) (parse_url($created['headers']['location'], PHP_URL_QUERY) ?? '');
    parse_str($locationQuery, $locationFields);
    $locationWidgetId = is_string($locationFields['id'] ?? null) ? $locationFields['id'] : '';
    if (preg_match('/^[a-f0-9]{32}$/D', $locationWidgetId) === 1) {
        $createdIds[] = $locationWidgetId;
    }
    $reloaded = $admin->request($created['headers']['location']);
    check($reloaded['status'] === 200, 'Created widget did not reload from its redirect target.');
    $createdForm = $readEditor($reloaded['body']);
    $widgetId = $createdForm['widget_id'];
    if (preg_match('/^[a-f0-9]{32}$/D', $widgetId) === 1 && !in_array($widgetId, $createdIds, true)) {
        $createdIds[] = $widgetId;
    }
    check(preg_match('/^[a-f0-9]{32}$/D', $widgetId) === 1 && $createdForm['revision'] === '1'
        && $createdForm['title'] === $ownedWidgetTitle,
        'Reloaded widget form did not show the persisted new record.');
    check(countRows($pdo, 'public_content_widgets') === $initialCount + 1,
        'Widget creation did not persist exactly one owned row.');
    $savedRow = row($pdo,
        'SELECT title, content_md, status, revision FROM public_content_widgets WHERE widget_id = ?',
        [$widgetId]);
    check($savedRow['title'] === $ownedWidgetTitle && $savedRow['content_md'] === $draftContent
        && $savedRow['status'] === 'draft' && (int) $savedRow['revision'] === 1,
        'Created widget state was not committed as a draft.');

    $stage = 'concurrent Admin save and stale revision refusal';
    $editRoute = $widgetRoute . '&id=' . rawurlencode($widgetId);
    $writerAForm = $readEditor($admin->request($editRoute)['body']);
    $otherAdmin = new Http($origin);
    $otherAdmin->login($seed);
    $writerBForm = $readEditor($otherAdmin->request($editRoute)['body']);
    check($writerAForm['revision'] === '1' && $writerBForm['revision'] === '1',
        'Independent Admin sessions did not load the same initial revision.');

    $winner = $admin->request($editRoute, array_replace($createPayload, [
        'csrf_token' => $writerAForm['csrf_token'], 'widget_action' => 'save',
        'widget_id' => $widgetId, 'revision' => $writerAForm['revision'],
        'title' => 'HTTP winning edit',
    ]));
    check($winner['status'] === 302 && isset($winner['headers']['location']),
        'Current-revision widget edit did not complete through post/redirect/get.');
    $winnerReload = $admin->request($winner['headers']['location']);
    check($winnerReload['status'] === 200, 'Saved widget did not reload after its redirect.');
    $winnerForm = $readEditor($winnerReload['body']);
    check($winnerForm['title'] === 'HTTP winning edit' && $winnerForm['revision'] === '2',
        'Reload did not expose the committed edit and incremented revision.');

    $stale = $otherAdmin->request($editRoute, array_replace($createPayload, [
        'csrf_token' => $writerBForm['csrf_token'], 'widget_action' => 'save',
        'widget_id' => $widgetId, 'revision' => $writerBForm['revision'],
        'title' => 'HTTP stale edit',
    ]));
    check($stale['status'] === 200, 'Stale widget save did not return the editor with a validation error.');
    $staleForm = $readEditor($stale['body']);
    check($staleForm['revision_error'] && $staleForm['title'] === 'HTTP stale edit'
        && $staleForm['revision'] === '1',
        'Stale conflict did not preserve both the submitted draft and its old revision token.');
    $afterStale = row($pdo,
        'SELECT title, revision FROM public_content_widgets WHERE widget_id = ?',
        [$widgetId]);
    check($afterStale['title'] === 'HTTP winning edit' && (int) $afterStale['revision'] === 2,
        'Stale Admin submission overwrote the newer committed widget revision.');

    $stage = 'owned widget cleanup';
    $delete = $pdo->prepare('DELETE FROM public_content_widgets WHERE widget_id = ?');
    foreach ($createdIds as $createdId) {
        $delete->execute([$createdId]);
    }
    $createdIds = [];
    check(countRows($pdo, 'public_content_widgets') === $initialCount,
        'Admin HTTP widget workflow did not restore the original row count.');
} catch (Throwable $exception) {
    $detail = $exception instanceof RuntimeException && !($exception instanceof PDOException) ? $exception->getMessage()
        : basename($exception->getFile()) . ' line ' . $exception->getLine();
    $failureMessage = 'FAIL public content widget Admin HTTP ' . $stage . ': ' . $detail;
} finally {
    if (isset($pdo) && $pdo instanceof PDO) {
        try {
            $findOwned = $pdo->prepare('SELECT widget_id FROM public_content_widgets WHERE title = ?');
            $findOwned->execute([$ownedWidgetTitle]);
            foreach ($findOwned->fetchAll(PDO::FETCH_COLUMN) as $ownedId) {
                if (is_string($ownedId) && preg_match('/^[a-f0-9]{32}$/D', $ownedId) === 1
                    && !in_array($ownedId, $createdIds, true)) {
                    $createdIds[] = $ownedId;
                }
            }
            $delete = $pdo->prepare('DELETE FROM public_content_widgets WHERE widget_id = ?');
            foreach ($createdIds as $createdId) {
                $delete->execute([$createdId]);
            }
        } catch (Throwable) {
            // The fixture owner reports the workflow failure; no other row is touched here.
        }
    }
}

if ($failureMessage !== null) {
    fwrite(STDERR, $failureMessage . "\n");
    exit(1);
}
echo "PASS public content widget Admin HTTP authorization, CSRF, preview, reload and stale revision workflow\n";
