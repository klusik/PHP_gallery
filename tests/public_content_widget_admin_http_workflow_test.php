<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/public_content_widget_admin_http_workflow_test.php
 * Module Type: Disposable Workflow Regression Test
 * Purpose: Verify the widget Admin lifecycle, public access gates, authorization, localized validation and stale revisions through real HTTP.
 * Responsibilities:
 *   - Use only the explicitly owned disposable workflow application and database.
 *   - Exercise actual Theme widget forms for CRUD, publication, ordering, discard and preview with isolated sessions.
 *   - Compare persisted publication transitions with anonymous public Home output.
 *   - Keep all-pages widget content and metadata out of private, password-protected and NSFW Gallery responses.
 *   - Compare the complete widget table after denied mutation requests to detect hidden row/order/revision writes.
 *   - Preserve both saved content choices and localized drafts across Admin locale switching.
 *   - Keep private Gallery identity out of the ordinary-visitor widget preview target.
 *   - Remove only the unique widget rows created by this workflow.
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
$draftContent = "# Widget HTTP draft\n\n"
    . 'Fixture marker ' . $widgetMarker . ': **bold phrase** and *italic phrase*.' . "\n\n"
    . '- [First custom label](https://example.test/widget-first-' . $widgetMarker . ")\n"
    . '- [Second custom label](/index.php?page=gallery&id=42)' . "\n\n"
    . "1. First ordered item\n2. Second ordered item";
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
        $selectValue = static function (string $name) use ($xpath, $form): string {
            $selected = $xpath->query('.//select[@name="' . $name . '"]/option[@selected]', $form)->item(0);
            return $selected instanceof DOMElement ? $selected->getAttribute('value') : '';
        };
        $titleInput = $xpath->query('.//input[@name="title"]', $form)->item(0);
        $content = $xpath->query('.//textarea[@name="content_md"]', $form)->item(0);
        $sourceLanguage = $xpath->query('.//select[@name="source_language"]/option[@selected]', $form)->item(0);
        $error = $xpath->query('//*[@data-widget-error-field]')->item(0);
        $errorField = $error instanceof DOMElement ? $error->getAttribute('data-widget-error-field') : '';
        $preview = $xpath->query('//*[@data-widget-preview-gallery-url]')->item(0);
        $discard = $xpath->query('.//a[contains(concat(" ", normalize-space(@class), " "), " button ") and contains(concat(" ", normalize-space(@class), " "), " secondary ")]', $form)->item(0);
        $html = $document->documentElement;
        return [
            'csrf_token' => $inputValue('csrf_token'),
            'widget_id' => $inputValue('widget_id'),
            'revision' => $inputValue('revision'),
            'title' => $titleInput instanceof DOMElement ? $titleInput->getAttribute('value') : '',
            'content_md' => $content instanceof DOMElement ? $content->textContent : '',
            'source_language' => $sourceLanguage instanceof DOMElement ? $sourceLanguage->getAttribute('value') : '',
            'preview_gallery_present' => $preview instanceof DOMElement,
            'preview_gallery_url' => $preview instanceof DOMElement ? $preview->getAttribute('data-widget-preview-gallery-url') : '',
            'status' => $selectValue('status'),
            'page_scope' => $selectValue('page_scope'),
            'placement_mode' => $selectValue('placement_mode'),
            'flow_slot' => $selectValue('flow_slot'),
            'active_language' => $html instanceof DOMElement ? $html->getAttribute('lang') : '',
            'error_field' => $errorField,
            'error_message' => $error instanceof DOMElement ? trim($error->textContent) : '',
            'revision_error' => $errorField === 'revision',
            'discard_url' => $discard instanceof DOMElement ? $discard->getAttribute('href') : '',
        ];
    };
    $readSelectedAdminLanguage = static function (string $html): string {
        $document = new DOMDocument();
        $previousLibxmlState = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML($html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousLibxmlState);
        }
        check($loaded, 'Admin Theme language form HTML could not be parsed.');
        $xpath = new DOMXPath($document);
        $selected = $xpath->query('//select[@name="cms_language"]/option[@selected]')->item(0);
        return $selected instanceof DOMElement ? $selected->getAttribute('value') : '';
    };
    $innerHtml = static function (DOMDocument $document, DOMNode $element): string {
        $html = '';
        foreach ($element->childNodes as $child) {
            $html .= $document->saveHTML($child) ?: '';
        }
        return $html;
    };
    $readPublicWidget = static function (array $response, string $expectedWidgetId) use ($innerHtml): array {
        check($response['status'] === 200 && is_string($response['body'] ?? null),
            'Anonymous public Home request did not return a page.');
        $html = $response['body'];
        $document = new DOMDocument();
        $previousLibxmlState = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML($html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousLibxmlState);
        }
        check($loaded, 'Public Home HTML could not be parsed.');
        $xpath = new DOMXPath($document);
        $article = $xpath->query('//article[@data-public-widget-id="' . $expectedWidgetId . '"]')->item(0);
        if (!$article instanceof DOMElement) {
            return [];
        }
        $region = $xpath->query('ancestor::*[@data-public-widget-zone][1]', $article)->item(0);
        $title = $xpath->query('./h2[contains(concat(" ", normalize-space(@class), " "), " public-content-widget-title ")]', $article)->item(0);
        $body = $xpath->query('./div[contains(concat(" ", normalize-space(@class), " "), " public-content-widget-body ")]', $article)->item(0);
        return [
            'zone' => $region instanceof DOMElement ? $region->getAttribute('data-public-widget-zone') : '',
            'title' => $title instanceof DOMElement ? $title->textContent : '',
            'body' => $body instanceof DOMElement ? $body->textContent : '',
            'body_html' => $body instanceof DOMElement ? $innerHtml($document, $body) : '',
        ];
    };
    $editor = $readEditor($formResponse['body']);
    check($editor['csrf_token'] !== '', 'Actual widget form did not render its CSRF token.');
    check($editor['preview_gallery_present'] && $editor['preview_gallery_url'] !== '',
        'Publicly accessible root Gallery was not offered as an ordinary-visitor widget preview target.');
    check(countRows($pdo, 'public_content_widgets') === $initialCount,
        'Anonymous requests or editor loading changed widget persistence.');

    $rootGallery = row($pdo, 'SELECT visibility, folder_path, nsfw_enabled, url_path, slug FROM galleries WHERE id = ?', [(int) $seed['root_id']]);
    check($rootGallery !== [], 'Widget preview privacy fixture is missing its root Gallery.');
    check((string) ($rootGallery['visibility'] ?? '') === 'public',
        'Widget preview privacy fixture requires its root Gallery to start public.');
    $stage = 'private Gallery excluded from widget preview target';
    try {
        $pdo->prepare("UPDATE galleries SET visibility = 'private' WHERE id = ?")
            ->execute([(int) $seed['root_id']]);
        $privateGallery = row($pdo, 'SELECT visibility FROM galleries WHERE id = ?', [(int) $seed['root_id']]);
        check(($privateGallery['visibility'] ?? '') === 'private',
            'Widget preview privacy fixture did not persist the private Gallery state.');
        $privateGalleryEditor = $admin->request($widgetRoute);
        $privateGalleryForm = $readEditor($privateGalleryEditor['body']);
        $privatePreviewParts = parse_url($privateGalleryForm['preview_gallery_url']);
        $privatePreviewQuery = [];
        if (is_array($privatePreviewParts)) {
            parse_str((string) ($privatePreviewParts['query'] ?? ''), $privatePreviewQuery);
        }
        $rootPublicPath = trim((string) ($rootGallery['url_path'] ?? ''), '/');
        if ($rootPublicPath === '') {
            $rootPublicPath = trim((string) ($rootGallery['slug'] ?? ''), '/');
        }
        $targetPublicPath = '';
        if (is_array($privatePreviewParts)) {
            if (($privatePreviewQuery['page'] ?? '') === 'gallery') {
                $targetPublicPath = trim((string) ($privatePreviewQuery['public_path'] ?? ''), '/');
            } else {
                $previewPath = '/' . trim(rawurldecode((string) ($privatePreviewParts['path'] ?? '')), '/');
                if (preg_match('~(?:^|/)gallery/(.+?)/?$~D', $previewPath, $galleryRouteMatch) === 1) {
                    $targetPublicPath = trim((string) ($galleryRouteMatch[1] ?? ''), '/');
                }
            }
        }
        $previewTargetsPrivateRoot = $targetPublicPath === '' || $rootPublicPath === ''
            || $targetPublicPath === $rootPublicPath;
        check($privateGalleryEditor['status'] === 200 && $privateGalleryForm['preview_gallery_present']
            && ($privateGalleryForm['preview_gallery_url'] === ''
                || !$previewTargetsPrivateRoot),
            'Private Gallery remained available as an ordinary-visitor widget preview target.');
    } finally {
        $pdo->prepare('UPDATE galleries SET visibility = ? WHERE id = ?')
            ->execute([(string) $rootGallery['visibility'], (int) $seed['root_id']]);
    }

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
    check($unsafeForm['active_language'] === 'en' && $unsafeForm['source_language'] === 'en',
        'Rejected unsafe-link creation changed its active Admin locale or submitted source language.');
    check($unsafeForm['error_field'] === 'content_md'
        && $unsafeForm['error_message'] === 'Use a site-relative destination or an HTTP(S) URL.',
        'Rejected unsafe-link creation did not identify and describe the content_md field error.');
    check(countRows($pdo, 'public_content_widgets') === $initialCount,
        'Rejected unsafe-link creation changed persistent widget rows.');

    $stage = 'active Admin locale switch with retained unsafe-link draft';
    $languageSwitch = $admin->request('/index.php?page=admin_theme', [
        'csrf_token' => $editor['csrf_token'], 'cms_language' => 'cs', 'theme_controls_changed' => '0',
    ]);
    check($languageSwitch['status'] === 302 && $admin->cookieValue('cms_admin_language') === 'cs',
        'The real cms_language action did not select Czech for this authenticated Admin session.');
    $languageSettings = $admin->request('/index.php?page=admin_theme');
    check($languageSettings['status'] === 200 && $readSelectedAdminLanguage($languageSettings['body']) === 'cs',
        'Admin Theme did not render Czech as the selected Admin locale after switching.');
    $czechEditorResponse = $admin->request($widgetRoute);
    check($czechEditorResponse['status'] === 200, 'Widget editor did not reload after switching the Admin locale.');
    $czechEditor = $readEditor($czechEditorResponse['body']);
    check($czechEditor['active_language'] === 'cs', 'Widget editor did not render in the selected Czech Admin locale.');
    $czechUnsafeCreate = $admin->request($widgetRoute, array_replace($createPayload, [
        'csrf_token' => $czechEditor['csrf_token'], 'content_md' => $forbiddenLinkDraft,
    ]));
    check($czechUnsafeCreate['status'] === 200,
        'Widget creation with a forbidden javascript: link was not revalidated after switching Admin locale.');
    $czechUnsafeForm = $readEditor($czechUnsafeCreate['body']);
    check($czechUnsafeForm['active_language'] === 'cs' && $czechUnsafeForm['source_language'] === 'en'
        && $czechUnsafeForm['title'] === $ownedWidgetTitle && $czechUnsafeForm['content_md'] === $forbiddenLinkDraft,
        'Czech unsafe-link refusal did not preserve the selected Admin locale, source language, title and Markdown draft.');
    check($czechUnsafeForm['error_field'] === 'content_md'
        && $czechUnsafeForm['error_message'] === 'Použijte cestu v rámci webu nebo adresu HTTP(S).'
        && $czechUnsafeForm['error_message'] !== $unsafeForm['error_message'],
        'Unsafe-link validation did not change to the Czech content_md message after switching Admin locale.');
    check(countRows($pdo, 'public_content_widgets') === $initialCount
        && row($pdo, 'SELECT widget_id FROM public_content_widgets WHERE title = ? OR content_md = ?',
            [$ownedWidgetTitle, $forbiddenLinkDraft]) === [],
        'Rejected unsafe-link drafts persisted a widget row after switching Admin locale.');

    $preview = $admin->request($widgetRoute, [
        'csrf_token' => $editor['csrf_token'], 'widget_action' => 'preview',
        'widget_preview_json' => '1', 'widget_id' => '', 'title' => 'Preview draft',
        'content_md' => $draftContent, 'appearance' => 'card',
    ], true);
    $previewHtml = is_array($preview['json'] ?? null) ? (string) ($preview['json']['html'] ?? '') : '';
    check($preview['status'] === 200 && is_array($preview['json']) && ($preview['json']['ok'] ?? null) === true
        && str_contains($previewHtml, '<h2>Widget HTTP draft</h2>')
        && str_contains($previewHtml, '<strong>bold phrase</strong>')
        && str_contains($previewHtml, '<em>italic phrase</em>')
        && str_contains($previewHtml, '<ul>') && str_contains($previewHtml, '<ol>')
        && str_contains($previewHtml, '>First custom label</a>')
        && str_contains($previewHtml, '>Second custom label</a>')
        && str_contains(strtolower((string) ($preview['headers']['cache-control'] ?? '')), 'no-store'),
        'Authenticated widget Markdown preview was not rendered as a no-store response.');
    $previewDocument = new DOMDocument();
    $previousLibxmlState = libxml_use_internal_errors(true);
    try {
        $loadedPreviewFragment = $previewDocument->loadHTML(
            '<!doctype html><html><body><div id="widget-preview-fragment">' . $previewHtml . '</div></body></html>'
        );
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previousLibxmlState);
    }
    check($loadedPreviewFragment, 'Authenticated Markdown preview fragment could not be parsed.');
    $previewXPath = new DOMXPath($previewDocument);
    $previewRoot = $previewXPath->query('//div[@id="widget-preview-fragment"]')->item(0);
    check($previewRoot instanceof DOMElement, 'Authenticated Markdown preview fragment wrapper was not rendered.');
    $previewBodyHtml = $innerHtml($previewDocument, $previewRoot);
    check(countRows($pdo, 'public_content_widgets') === $initialCount,
        'Authenticated widget preview persisted a draft.');

    $stage = 'create and reload persistence';
    $createPayload['source_language'] = 'sv';
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
        'SELECT title, content_md, status, source_language, revision FROM public_content_widgets WHERE widget_id = ?',
        [$widgetId]);
    check($savedRow['title'] === $ownedWidgetTitle && $savedRow['content_md'] === $draftContent
        && $savedRow['status'] === 'draft' && $savedRow['source_language'] === 'sv'
        && (int) $savedRow['revision'] === 1,
        'Created widget state was not committed as a draft.');
    $readPersistedWidget = static function () use ($pdo, $widgetId): array {
        return row($pdo,
            'SELECT title, content_md, status, page_scope, placement_mode, flow_slot, floating_anchor, '
                . 'x_permille, y_permille, width_px, mobile_fallback, appearance, sort_order, source_language, revision '
                . 'FROM public_content_widgets WHERE widget_id = ?',
            [$widgetId]);
    };

    $stage = 'real Theme Appearance save preserves every widget database column';
    $themePage = $admin->request('/index.php?page=admin_theme');
    check($themePage['status'] === 200 && is_string($themePage['body'] ?? null),
        'Authenticated Theme Appearance page did not load before the real save.');
    $themeDocument = new DOMDocument();
    $previousLibxmlState = libxml_use_internal_errors(true);
    try {
        $loadedThemePage = $themeDocument->loadHTML($themePage['body']);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previousLibxmlState);
    }
    check($loadedThemePage, 'Authenticated Theme Appearance page could not be parsed.');
    $themeXPath = new DOMXPath($themeDocument);
    $themeForm = $themeXPath->query('//form[@data-theme-form]')->item(0);
    $themeCsrf = $themeXPath->query('//form[@data-theme-form]//input[@name="csrf_token"]')->item(0);
    check($themeForm instanceof DOMElement && $themeCsrf instanceof DOMElement
        && $themeXPath->query('//form[@data-theme-form]//*[@name="theme_accent"]')->length === 1,
        'The production Theme Appearance form or its own CSRF field was not rendered.');
    $widgetRowsBeforeThemeSave = $pdo->query('SELECT * FROM public_content_widgets ORDER BY widget_id')
        ->fetchAll(PDO::FETCH_ASSOC);
    check($widgetRowsBeforeThemeSave !== [], 'Theme preservation assertion requires a persisted widget row.');
    $appSettingsBeforeThemeSave = $pdo->query(
        'SELECT setting_key, setting_value, updated_at FROM app_settings ORDER BY setting_key'
    )->fetchAll(PDO::FETCH_ASSOC);
    try {
        $themeSave = $admin->request('/index.php?page=admin_theme', [
            'csrf_token' => $themeCsrf->getAttribute('value'),
            'theme_active_tab' => 'admin-theme-tab-appearance',
            'theme_active_appearance_subtab' => 'admin-theme-appearance-subtab-colors',
            'theme_controls_changed' => '1',
            'site_name' => 'Theme appearance widget preservation',
            'theme_accent' => '#275d8f', 'theme_accent_dark' => '#153c62',
            'theme_paper' => '#f0f3f7', 'theme_panel' => '#ffffff',
            'theme_gallery_panel' => '#f8fafc', 'theme_header_text' => '#102030',
            'theme_hero_text' => '#203040', 'theme_radius' => '14', 'theme_font' => 'sans',
            'theme_background_opacity' => '65', 'theme_background_optimized_max_side' => '1920',
            'theme_background_source' => '', 'theme_page_width' => 'default',
            'theme_page_width_custom' => '1440', 'theme_gps_pin_enabled' => '1',
            'theme_gps_pin_background_enabled' => '1', 'theme_gps_pin_size' => '26',
            'theme_gps_pin_background_size' => '22', 'theme_hero_tag_visible_limit' => '20',
            'theme_hero_tag_display_all' => '0', 'theme_hero_tag_scrollbar_enabled' => '1',
            'theme_hero_tag_scrollbar_rows' => '5', 'theme_hero_tag_sort_mode' => 'usage',
            'theme_gallery_info_motion_ms' => '320', 'theme_admin_side_panel_motion_ms' => '260',
            'theme_gallery_description_layout' => 'horizontal', 'theme_gallery_count_badge_mode' => 'both',
            'theme_gallery_count_badge_icons_enabled' => '1',
            'public_thumbnail_rendering_mode' => 'progressive', 'theme_lightbox_browsing_mode' => 'single',
            'pagination_enabled' => '0', 'pagination_columns' => '4', 'pagination_rows' => '5',
            'home_gallery_grid_columns' => '4', 'home_gallery_grid_rows' => '5',
            'tag_page_gallery_grid_columns' => '4', 'tag_page_gallery_grid_rows' => '5',
            'tag_page_gallery_description_layout' => 'vertical',
            'theme_favorite_gallery_types' => [], 'theme_favorite_gallery_ids' => [],
        ]);
        check($themeSave['status'] === 302 && isset($themeSave['headers']['location']),
            'The production Theme Appearance save did not complete through post/redirect/get.');
        $savedThemeAccent = row($pdo, 'SELECT setting_value FROM app_settings WHERE setting_key = ?', ['theme_accent']);
        check(($savedThemeAccent['setting_value'] ?? null) === '#275d8f',
            'The real Theme Appearance save did not persist its submitted appearance change.');
        $widgetRowsAfterThemeSave = $pdo->query('SELECT * FROM public_content_widgets ORDER BY widget_id')
            ->fetchAll(PDO::FETCH_ASSOC);
        check($widgetRowsAfterThemeSave === $widgetRowsBeforeThemeSave,
            'A real Theme Appearance save changed one or more complete public-widget database rows.');
    } finally {
        $pdo->beginTransaction();
        try {
            $pdo->exec('DELETE FROM app_settings');
            $restoreAppSetting = $pdo->prepare(
                'INSERT INTO app_settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?)'
            );
            foreach ($appSettingsBeforeThemeSave as $setting) {
                $restoreAppSetting->execute([$setting['setting_key'], $setting['setting_value'], $setting['updated_at']]);
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    $savedBeforeLocaleSwitch = $readPersistedWidget();
    $savedLanguageSwitch = $admin->request('/index.php?page=admin_theme', [
        'csrf_token' => $createdForm['csrf_token'], 'cms_language' => 'de', 'theme_controls_changed' => '0',
    ]);
    check($savedLanguageSwitch['status'] === 302 && $admin->cookieValue('cms_admin_language') === 'de',
        'The authenticated Admin locale could not be changed after saving a widget.');
    $savedLanguageSettings = $admin->request('/index.php?page=admin_theme');
    check($savedLanguageSettings['status'] === 200
        && $readSelectedAdminLanguage($savedLanguageSettings['body']) === 'de',
        'Theme did not retain German as the Admin locale after saving a widget.');
    $savedLanguageReload = $admin->request($created['headers']['location']);
    check($savedLanguageReload['status'] === 200, 'Saved widget did not reopen after changing the Admin locale.');
    $savedLanguageForm = $readEditor($savedLanguageReload['body']);
    check($savedLanguageForm['active_language'] === 'de' && $savedLanguageForm['source_language'] === 'sv'
        && $savedLanguageForm['title'] === $ownedWidgetTitle && $savedLanguageForm['content_md'] === $draftContent
        && $savedLanguageForm['status'] === 'draft' && $savedLanguageForm['flow_slot'] === 'home_after_grid'
        && $readPersistedWidget() === $savedBeforeLocaleSwitch,
        'Changing the Admin locale altered saved widget content, authored language, placement or persistence.');
    $createdForm = $savedLanguageForm;

    $stage = 'localized authenticated preview and deletion refusals';
    $invalidGermanPreview = $admin->request($widgetRoute, array_replace($createPayload, [
        'csrf_token' => $createdForm['csrf_token'], 'widget_action' => 'preview',
        'widget_preview_json' => '1', 'widget_id' => $widgetId, 'title' => str_repeat('T', 181),
    ]), true);
    check($invalidGermanPreview['status'] === 422 && is_array($invalidGermanPreview['json'])
        && ($invalidGermanPreview['json']['ok'] ?? null) === false
        && ($invalidGermanPreview['json']['field'] ?? '') === 'title'
        && ($invalidGermanPreview['json']['message'] ?? '') === 'Der Widget-Titel darf höchstens 180 Zeichen enthalten.'
        && str_contains(strtolower((string) ($invalidGermanPreview['headers']['cache-control'] ?? '')), 'no-store'),
        'Authenticated invalid JSON preview did not return a localized German error.');
    $unconfirmedGermanDelete = $admin->request($widgetRoute, array_replace($createPayload, [
        'csrf_token' => $createdForm['csrf_token'], 'widget_action' => 'delete',
        'widget_id' => $widgetId, 'revision' => $createdForm['revision'], 'confirm_delete' => '0',
    ]));
    $unconfirmedDeleteForm = $readEditor($unconfirmedGermanDelete['body']);
    check($unconfirmedGermanDelete['status'] === 200 && $unconfirmedDeleteForm['active_language'] === 'de'
        && $unconfirmedDeleteForm['error_field'] === 'confirm_delete'
        && $unconfirmedDeleteForm['error_message'] === 'Bestätigen Sie zuerst die endgültige Löschung.'
        && $readPersistedWidget() === $savedBeforeLocaleSwitch,
        'Unconfirmed deletion did not show the localized error while preserving the saved widget.');

    $stage = 'invalid page-scope placement save preserves the German draft and complete row';
    $invalidPlacementTitle = 'Rejected placement draft ' . $widgetMarker;
    $invalidPlacementContent = 'Retained content for the rejected Gallery-only homepage slot ' . $widgetMarker;
    $persistedBeforeInvalidPlacement = row($pdo,
        'SELECT * FROM public_content_widgets WHERE widget_id = ?', [$widgetId]);
    $invalidPlacementSave = $admin->request($widgetRoute, array_replace($createPayload, [
        'csrf_token' => $createdForm['csrf_token'],
        'widget_action' => 'save', 'widget_id' => $widgetId, 'revision' => $createdForm['revision'],
        'title' => $invalidPlacementTitle, 'content_md' => $invalidPlacementContent,
        'status' => 'draft', 'page_scope' => 'gallery',
        'placement_mode' => 'flow', 'flow_slot' => 'home_after_grid',
    ]));
    check($invalidPlacementSave['status'] === 200,
        'Invalid Gallery-only homepage placement did not return the editor with a field error.');
    $invalidPlacementForm = $readEditor($invalidPlacementSave['body']);
    check($invalidPlacementForm['active_language'] === 'de'
        && $invalidPlacementForm['error_field'] === 'flow_slot'
        && $invalidPlacementForm['error_message'] === 'Startseiten-Rasterpositionen sind für Widgets nur auf Galerieseiten nicht verfügbar.',
        'Invalid page-scope/placement pair did not show its localized field-specific error.');
    check($invalidPlacementForm['title'] === $invalidPlacementTitle
        && $invalidPlacementForm['content_md'] === $invalidPlacementContent
        && $invalidPlacementForm['status'] === 'draft'
        && $invalidPlacementForm['page_scope'] === 'gallery'
        && $invalidPlacementForm['placement_mode'] === 'flow'
        && $invalidPlacementForm['flow_slot'] === 'home_after_grid'
        && $invalidPlacementForm['revision'] === $createdForm['revision'],
        'Rejected placement save did not preserve the submitted draft values and revision in the editor.');
    check(row($pdo, 'SELECT * FROM public_content_widgets WHERE widget_id = ?', [$widgetId])
        === $persistedBeforeInvalidPlacement,
        'Rejected placement save changed at least one persisted widget column or revision.');

    $stage = 'Admin authorization and CSRF on widget mutations';
    $protectedSaveBaseline = $readPersistedWidget();
    $readAllPersistedWidgets = static function () use ($pdo): array {
        $statement = $pdo->query('SELECT * FROM public_content_widgets ORDER BY widget_id');
        check($statement !== false, 'Could not read the complete widget mutation snapshot.');
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    };
    $protectedMutationBaseline = $readAllPersistedWidgets();
    $craftedPublishedSave = array_replace($createPayload, [
        'widget_action' => 'save', 'widget_id' => $widgetId, 'revision' => $createdForm['revision'],
        'title' => 'Rejected unauthorized edit', 'content_md' => 'Rejected unauthorized content',
        'status' => 'published',
    ]);
    $anonymousSave = $anonymous->request($widgetRoute,
        array_replace($craftedPublishedSave, ['csrf_token' => 'missing']), true);
    check($anonymousSave['status'] === 401 && is_array($anonymousSave['json'])
        && ($anonymousSave['json']['error_code'] ?? '') === 'auth.admin_required',
        'Anonymous existing-widget save did not fail at the Admin authorization boundary.');
    check($readPersistedWidget() === $protectedSaveBaseline
        && $readAllPersistedWidgets() === $protectedMutationBaseline,
        'Anonymous existing-widget save changed persisted widget content, order, timestamp or revision.');
    $invalidCsrfSave = $admin->request($widgetRoute,
        array_replace($craftedPublishedSave, ['csrf_token' => 'invalid']), true);
    check($invalidCsrfSave['status'] === 400 && is_array($invalidCsrfSave['json'])
        && ($invalidCsrfSave['json']['error_code'] ?? '') === 'security.invalid_csrf',
        'Invalid-CSRF existing-widget save was not rejected.');
    check($readPersistedWidget() === $protectedSaveBaseline
        && $readAllPersistedWidgets() === $protectedMutationBaseline,
        'Invalid-CSRF existing-widget save changed persisted widget content, order, timestamp or revision.');

    $protectedMutationCases = [
        'duplicate' => ['widget_action' => 'duplicate', 'widget_id' => $widgetId],
        'reorder' => [
            'widget_action' => 'reorder',
            'ordering[0][widget_id]' => $widgetId,
            'ordering[0][revision]' => $createdForm['revision'],
            'ordering[0][sort_order]' => '10',
        ],
        'delete' => [
            'widget_action' => 'delete', 'widget_id' => $widgetId,
            'revision' => $createdForm['revision'], 'confirm_delete' => '1',
        ],
    ];
    foreach ($protectedMutationCases as $action => $fields) {
        $anonymousMutation = $anonymous->request($widgetRoute,
            array_replace($fields, ['csrf_token' => 'missing']), true);
        check($anonymousMutation['status'] === 401
            && is_array($anonymousMutation['json'])
            && ($anonymousMutation['json']['error_code'] ?? '') === 'auth.admin_required',
            'Anonymous widget ' . $action . ' did not fail at the Admin authorization boundary.');
        check($readAllPersistedWidgets() === $protectedMutationBaseline,
            'Anonymous widget ' . $action . ' changed a persisted widget row, order, timestamp or revision.');
        $invalidCsrfMutation = $admin->request($widgetRoute,
            array_replace($fields, ['csrf_token' => 'invalid']), true);
        check($invalidCsrfMutation['status'] === 400
            && is_array($invalidCsrfMutation['json'])
            && ($invalidCsrfMutation['json']['error_code'] ?? '') === 'security.invalid_csrf',
            'Invalid-CSRF widget ' . $action . ' was not rejected.');
        check($readAllPersistedWidgets() === $protectedMutationBaseline,
            'Invalid-CSRF widget ' . $action . ' changed a persisted widget row, order, timestamp or revision.');
    }
    check(countRows($pdo, 'public_content_widgets') === $initialCount + 1,
        'Rejected existing-widget save, duplicate, reorder or delete requests changed widget persistence.');

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

    $winningPersistedWidget = $readPersistedWidget();
    $staleDraftTitle = 'HTTP stale edit';
    $staleDraftContent = 'HTTP stale edit content';
    $stale = $otherAdmin->request($editRoute, array_replace($createPayload, [
        'csrf_token' => $writerBForm['csrf_token'], 'widget_action' => 'save',
        'widget_id' => $widgetId, 'revision' => $writerBForm['revision'],
        'title' => $staleDraftTitle, 'content_md' => $staleDraftContent, 'status' => 'published',
    ]));
    check($stale['status'] === 200, 'Stale widget save did not return the editor with a validation error.');
    $staleForm = $readEditor($stale['body']);
    check($staleForm['revision_error'] && $staleForm['title'] === $staleDraftTitle
        && $staleForm['content_md'] === $staleDraftContent && $staleForm['status'] === 'published'
        && $staleForm['revision'] === '1',
        'Stale conflict did not preserve submitted title, content, status and old revision token.');
    check($readPersistedWidget() === $winningPersistedWidget,
        'Stale Admin submission overwrote saved content, status, revision or placement.');

    $stage = 'discard retained edit draft through the actual editor link';
    check($staleForm['discard_url'] !== '', 'Rejected edit did not render the actual discard link.');
    $discarded = $otherAdmin->request($staleForm['discard_url']);
    check($discarded['status'] === 200, 'Discard link did not reload the authenticated widget editor.');
    $discardedForm = $readEditor($discarded['body']);
    check($discardedForm['widget_id'] === $widgetId && $discardedForm['revision'] === '2'
        && $discardedForm['title'] === 'HTTP winning edit',
        'Discard link did not replace the rejected draft with the latest persisted widget.');

    $stage = 'publish, disable and republish through authenticated Admin HTTP';
    $savedPlacement = row($pdo,
        'SELECT title, content_md, status, page_scope, placement_mode, flow_slot, floating_anchor, '
            . 'x_permille, y_permille, width_px, mobile_fallback, appearance, sort_order, source_language '
            . 'FROM public_content_widgets WHERE widget_id = ?',
        [$widgetId]);
    check($savedPlacement['title'] === 'HTTP winning edit' && $savedPlacement['content_md'] === $draftContent
        && $savedPlacement['status'] === 'draft' && $savedPlacement['page_scope'] === 'home'
        && $savedPlacement['placement_mode'] === 'flow' && $savedPlacement['flow_slot'] === 'home_after_grid',
        'Saved widget content or placement changed before publication lifecycle checks.');
    $assertSavedPlacement = static function (array $row) use ($savedPlacement): void {
        foreach (array_keys($savedPlacement) as $field) {
            if ($field === 'status') {
                continue;
            }
            check($row[$field] === $savedPlacement[$field],
                'Publication transition modified saved widget field ' . $field . '.');
        }
    };
    $publicBeforePublish = $readPublicWidget($anonymous->request('/'), $widgetId);
    check($publicBeforePublish === [], 'Draft widget appeared in anonymous public Home output.');
    $changeWidgetStatus = static function (string $status) use (
        $admin, $editRoute, $createPayload, $widgetId, $readEditor, $pdo
    ): array {
        $editorResponse = $admin->request($editRoute);
        check($editorResponse['status'] === 200, 'Widget editor did not load before a publication-state change.');
        $currentForm = $readEditor($editorResponse['body']);
        $mutation = $admin->request($editRoute, array_replace($createPayload, [
            'csrf_token' => $currentForm['csrf_token'], 'widget_action' => 'save',
            'widget_id' => $widgetId, 'revision' => $currentForm['revision'],
            'title' => $currentForm['title'], 'content_md' => $currentForm['content_md'], 'status' => $status,
        ]));
        check($mutation['status'] === 302 && isset($mutation['headers']['location']),
            'Widget ' . $status . ' transition did not use the authenticated Admin post/redirect/get route.');
        $reload = $admin->request($mutation['headers']['location']);
        check($reload['status'] === 200, 'Widget editor did not reload after the ' . $status . ' transition.');
        $form = $readEditor($reload['body']);
        check($form['status'] === $status, 'Reloaded Admin editor did not show widget state ' . $status . '.');
        $persisted = row($pdo,
            'SELECT title, content_md, status, page_scope, placement_mode, flow_slot, floating_anchor, '
                . 'x_permille, y_permille, width_px, mobile_fallback, appearance, sort_order, source_language, revision '
                . 'FROM public_content_widgets WHERE widget_id = ?',
            [$widgetId]);
        return ['form' => $form, 'row' => $persisted];
    };
    $published = $changeWidgetStatus('published');
    check($published['row']['status'] === 'published' && (int) $published['row']['revision'] === 3,
        'Publish transition did not persist and increment the widget revision.');
    $assertSavedPlacement($published['row']);
    $publishedPublic = $readPublicWidget($anonymous->request('/'), $widgetId);
    check(($publishedPublic['zone'] ?? '') === 'home_after_grid'
        && ($publishedPublic['title'] ?? '') === 'HTTP winning edit'
        && str_contains((string) ($publishedPublic['body'] ?? ''), $widgetMarker)
        && ($publishedPublic['body_html'] ?? '') === $previewBodyHtml,
        'Published widget did not match the Admin preview fragment or saved Home placement.');

    $disabled = $changeWidgetStatus('disabled');
    check($disabled['row']['status'] === 'disabled' && (int) $disabled['row']['revision'] === 4
        && $disabled['form']['title'] === $savedPlacement['title']
        && $disabled['form']['content_md'] === $savedPlacement['content_md']
        && $disabled['form']['flow_slot'] === $savedPlacement['flow_slot'],
        'Disable transition changed the saved widget content or placement.');
    $assertSavedPlacement($disabled['row']);
    check($readPublicWidget($anonymous->request('/'), $widgetId) === [],
        'Disabled widget remained in anonymous public Home output after reload.');

    $republished = $changeWidgetStatus('published');
    check($republished['row']['status'] === 'published' && (int) $republished['row']['revision'] === 5
        && $republished['form']['title'] === $savedPlacement['title']
        && $republished['form']['content_md'] === $savedPlacement['content_md']
        && $republished['form']['flow_slot'] === $savedPlacement['flow_slot'],
        'Republish transition changed the saved widget content or placement.');
    $assertSavedPlacement($republished['row']);
    $republishedPublic = $readPublicWidget($anonymous->request('/'), $widgetId);
    check(($republishedPublic['zone'] ?? '') === 'home_after_grid'
        && ($republishedPublic['title'] ?? '') === $savedPlacement['title']
        && str_contains((string) ($republishedPublic['body'] ?? ''), $widgetMarker),
        'Republished widget did not retain its saved content and public placement.');
    $winnerForm = $republished['form'];

    $stage = 'duplicate saved widget as an independent draft';
    $duplicate = $admin->request($editRoute, [
        'csrf_token' => $winnerForm['csrf_token'], 'widget_action' => 'duplicate',
        'widget_id' => $widgetId, 'revision' => $winnerForm['revision'],
    ]);
    check($duplicate['status'] === 302 && isset($duplicate['headers']['location']),
        'Valid widget duplication did not use post/redirect/get.');
    $duplicateQuery = (string) (parse_url($duplicate['headers']['location'], PHP_URL_QUERY) ?? '');
    parse_str($duplicateQuery, $duplicateFields);
    $duplicateId = is_string($duplicateFields['id'] ?? null) ? $duplicateFields['id'] : '';
    if (preg_match('/^[a-f0-9]{32}$/D', $duplicateId) === 1) {
        $createdIds[] = $duplicateId;
    }
    check(preg_match('/^[a-f0-9]{32}$/D', $duplicateId) === 1 && $duplicateId !== $widgetId,
        'Duplicated widget did not receive an independent stable identity.');
    $duplicateReload = $admin->request($duplicate['headers']['location']);
    check($duplicateReload['status'] === 200, 'Duplicated widget did not reload from its redirect target.');
    $duplicateForm = $readEditor($duplicateReload['body']);
    check($duplicateForm['widget_id'] === $duplicateId && $duplicateForm['revision'] === '1'
        && $duplicateForm['title'] === 'HTTP winning edit',
        'Duplicated widget did not load as a new independent draft.');
    $duplicateRow = row($pdo,
        'SELECT title, content_md, status, revision, sort_order FROM public_content_widgets WHERE widget_id = ?',
        [$duplicateId]);
    check($duplicateRow['title'] === 'HTTP winning edit' && $duplicateRow['content_md'] === $draftContent
        && $duplicateRow['status'] === 'draft' && (int) $duplicateRow['revision'] === 1
        && (int) $duplicateRow['sort_order'] === 0,
        'Duplicate did not preserve authored content while forcing unpublished draft status.');
    check(countRows($pdo, 'public_content_widgets') === $initialCount + 2,
        'Widget duplication did not persist exactly one additional owned row.');

    $stage = 'revision-checked widget reorder';
    $currentOriginalRevision = $republished['form']['revision'];
    $currentDuplicateRevision = $duplicateForm['revision'];
    $orderFields = [
        'csrf_token' => $duplicateForm['csrf_token'], 'widget_action' => 'reorder',
        'ordering[0][widget_id]' => $widgetId,
        'ordering[0][revision]' => $currentOriginalRevision,
        'ordering[0][sort_order]' => '10',
        'ordering[1][widget_id]' => $duplicateId,
        'ordering[1][revision]' => $currentDuplicateRevision,
        'ordering[1][sort_order]' => '0',
    ];
    $invalidCsrfReorder = $admin->request($widgetRoute,
        array_replace($orderFields, ['csrf_token' => 'invalid']), true);
    check($invalidCsrfReorder['status'] === 400 && is_array($invalidCsrfReorder['json'])
        && ($invalidCsrfReorder['json']['error_code'] ?? '') === 'security.invalid_csrf',
        'Invalid-CSRF widget reorder was not rejected.');
    $reordered = $admin->request($widgetRoute, $orderFields);
    check($reordered['status'] === 302 && isset($reordered['headers']['location']),
        'Valid widget reorder did not use post/redirect/get.');
    $originalOrderRow = row($pdo,
        'SELECT sort_order, revision FROM public_content_widgets WHERE widget_id = ?', [$widgetId]);
    $duplicateOrderRow = row($pdo,
        'SELECT sort_order, revision FROM public_content_widgets WHERE widget_id = ?', [$duplicateId]);
    $reorderedOriginalRevision = (int) $currentOriginalRevision + 1;
    $reorderedDuplicateRevision = (int) $currentDuplicateRevision + 1;
    check((int) $originalOrderRow['sort_order'] === 10
        && (int) $originalOrderRow['revision'] === $reorderedOriginalRevision
        && (int) $duplicateOrderRow['sort_order'] === 0
        && (int) $duplicateOrderRow['revision'] === $reorderedDuplicateRevision,
        'Reorder did not persist both owned positions with revision increments.');
    $staleReorder = $admin->request($widgetRoute, $orderFields);
    check($staleReorder['status'] === 200 && $readEditor($staleReorder['body'])['error_field'] === 'revision',
        'Stale widget reorder did not return an editor revision conflict.');
    $afterStaleOrder = row($pdo,
        'SELECT sort_order, revision FROM public_content_widgets WHERE widget_id = ?', [$widgetId]);
    $afterStaleDuplicateOrder = row($pdo,
        'SELECT sort_order, revision FROM public_content_widgets WHERE widget_id = ?', [$duplicateId]);
    check((int) $afterStaleOrder['sort_order'] === 10
        && (int) $afterStaleOrder['revision'] === $reorderedOriginalRevision
        && (int) $afterStaleDuplicateOrder['sort_order'] === 0
        && (int) $afterStaleDuplicateOrder['revision'] === $reorderedDuplicateRevision,
        'Stale widget reorder partially changed the owned widget positions.');

    $stage = 'confirmed and revision-checked widget deletion';
    $duplicateRoute = $widgetRoute . '&id=' . rawurlencode($duplicateId);
    $currentDuplicateForm = $readEditor($admin->request($duplicateRoute)['body']);
    check($currentDuplicateForm['revision'] === '2', 'Reordered duplicate did not reload at its current revision.');
    $unconfirmedDelete = $admin->request($duplicateRoute, [
        'csrf_token' => $currentDuplicateForm['csrf_token'], 'widget_action' => 'delete',
        'widget_id' => $duplicateId, 'revision' => $currentDuplicateForm['revision'],
    ]);
    check($unconfirmedDelete['status'] === 200
        && $readEditor($unconfirmedDelete['body'])['error_field'] === 'confirm_delete',
        'Widget deletion without explicit confirmation was not refused in the editor.');
    check(row($pdo, 'SELECT widget_id FROM public_content_widgets WHERE widget_id = ?', [$duplicateId]) !== [],
        'Unconfirmed widget deletion changed persistence.');
    $staleDelete = $admin->request($duplicateRoute, [
        'csrf_token' => $currentDuplicateForm['csrf_token'], 'widget_action' => 'delete',
        'widget_id' => $duplicateId, 'revision' => '1', 'confirm_delete' => '1',
    ]);
    check($staleDelete['status'] === 200 && $readEditor($staleDelete['body'])['error_field'] === 'revision',
        'Confirmed stale widget deletion did not return an editor revision conflict.');
    check(row($pdo, 'SELECT widget_id FROM public_content_widgets WHERE widget_id = ?', [$duplicateId]) !== [],
        'Stale widget deletion changed persistence.');
    $deleted = $admin->request($duplicateRoute, [
        'csrf_token' => $currentDuplicateForm['csrf_token'], 'widget_action' => 'delete',
        'widget_id' => $duplicateId, 'revision' => $currentDuplicateForm['revision'], 'confirm_delete' => '1',
    ]);
    check($deleted['status'] === 302 && isset($deleted['headers']['location']),
        'Confirmed current-revision widget deletion did not use post/redirect/get.');
    check(row($pdo, 'SELECT widget_id FROM public_content_widgets WHERE widget_id = ?', [$duplicateId]) === []
        && countRows($pdo, 'public_content_widgets') === $initialCount + 1,
        'Confirmed widget deletion did not remove exactly its selected row.');

    $stage = 'published widget withheld by public Gallery access gates';
    $widgetEditorResponse = $admin->request($widgetRoute . '&id=' . rawurlencode($widgetId));
    check($widgetEditorResponse['status'] === 200, 'Owned widget editor did not load before Gallery access checks.');
    $widgetEditorForm = $readEditor($widgetEditorResponse['body']);
    $accessProbeTitle = 'Widget access probe ' . $widgetMarker;
    $accessProbeContent = '**Widget access probe body** ' . $widgetMarker;
    $accessProbeSave = $admin->request($widgetRoute, array_replace($createPayload, [
        'csrf_token' => $widgetEditorForm['csrf_token'],
        'widget_action' => 'save', 'widget_id' => $widgetId,
        'revision' => $widgetEditorForm['revision'],
        'title' => $accessProbeTitle, 'content_md' => $accessProbeContent,
        'status' => 'published', 'page_scope' => 'all',
    ]));
    check($accessProbeSave['status'] === 302 && isset($accessProbeSave['headers']['location']),
        'All-pages access probe widget did not save through the authenticated Admin route.');
    $accessProbeRow = row($pdo,
        'SELECT title, content_md, status, page_scope FROM public_content_widgets WHERE widget_id = ?', [$widgetId]);
    check($accessProbeRow['title'] === $accessProbeTitle && $accessProbeRow['content_md'] === $accessProbeContent
        && $accessProbeRow['status'] === 'published' && $accessProbeRow['page_scope'] === 'all',
        'Gallery access probe widget did not persist its unique published all-pages content.');
    $ordinaryGalleryVisitor = new Http($origin);
    $ordinaryGallery = $ordinaryGalleryVisitor->request('/index.php?page=gallery&public_path=seed');
    check($ordinaryGallery['status'] === 200
        && str_contains($ordinaryGallery['body'], 'data-public-widget-id="' . $widgetId . '"')
        && str_contains($ordinaryGallery['body'], $accessProbeTitle)
        && str_contains($ordinaryGallery['body'], $widgetMarker),
        'Published all-pages widget did not render on the ordinarily accessible public Gallery control page.');
    $assertProbeWidgetAbsent = static function (array $response, string $context) use (
        $widgetId, $accessProbeTitle, $widgetMarker
    ): void {
        $html = (string) ($response['body'] ?? '');
        check(!str_contains($html, 'data-public-widget-id="' . $widgetId . '"')
            && !str_contains($html, $widgetId) && !str_contains($html, $accessProbeTitle)
            && !str_contains($html, $widgetMarker),
            'Denied or gated ' . $context . ' Gallery response exposed widget content or metadata.');
    };

    try {
        $pdo->prepare("UPDATE galleries SET visibility = 'private' WHERE id = ?")
            ->execute([(int) $seed['root_id']]);
        $privateGallery = (new Http($origin))->request('/index.php?page=gallery&public_path=seed');
        check($privateGallery['status'] === 404, 'Private Gallery did not retain its public not-found response.');
        $assertProbeWidgetAbsent($privateGallery, 'private');
    } finally {
        $pdo->prepare('UPDATE galleries SET visibility = ? WHERE id = ?')
            ->execute([(string) $rootGallery['visibility'], (int) $seed['root_id']]);
    }

    $passwordGallery = (new Http($origin))->request('/index.php?page=gallery&public_path=seed/protected');
    check($passwordGallery['status'] === 200 && str_contains($passwordGallery['body'], 'name="gallery_password"'),
        'Password-protected Gallery did not retain its password prompt.');
    $assertProbeWidgetAbsent($passwordGallery, 'password-protected');

    try {
        $pdo->prepare('UPDATE galleries SET nsfw_enabled = 1 WHERE id = ?')
            ->execute([(int) $seed['root_id']]);
        $nsfwGallery = (new Http($origin))->request('/index.php?page=gallery&public_path=seed');
        check($nsfwGallery['status'] === 200 && str_contains($nsfwGallery['body'], 'name="adult_confirmed"'),
            'NSFW Gallery did not retain its visitor age-confirmation gate.');
        $assertProbeWidgetAbsent($nsfwGallery, 'NSFW');
    } finally {
        $pdo->prepare('UPDATE galleries SET nsfw_enabled = ? WHERE id = ?')
            ->execute([(int) $rootGallery['nsfw_enabled'], (int) $seed['root_id']]);
    }

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
            $findOwned = $pdo->prepare('SELECT widget_id FROM public_content_widgets WHERE title = ? OR content_md = ?');
            $findOwned->execute([$ownedWidgetTitle, $draftContent]);
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
echo "PASS public content widget Admin HTTP authorization, CSRF, Theme save preservation, publication, public Gallery access gates, CRUD, reorder, discard, preview and revision workflow\n";
