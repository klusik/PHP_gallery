<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/content_language_workflow_integration_test.php
 * Module Type: Regression Test
 * Purpose: Verify effective-language metadata across real application routes.
 * Responsibilities:
 *   - Exercise translated home cards, nested breadcrumbs, editors, and map caches
 *   - Restore every changed row in the owned disposable HTTP/MySQL fixture
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

require_once __DIR__ . '/support/gallery_workflow_http.php';

use GalleryWorkflow\Http;
use function GalleryWorkflow\check;
use function GalleryWorkflow\fixtureDatabase;
use function GalleryWorkflow\row;
use function GalleryWorkflow\validateFixture;

if (!getenv('GALLERY_WORKFLOW_FIXTURE')) {
    $required = getenv('GALLERY_WORKFLOW_REQUIRED') === '1';
    echo ($required ? 'BLOCKED' : 'SKIP') . " gallery workflow metadata language requires disposable runner\n";
    exit($required ? 1 : 0);
}

/**
 * Parse an actual application page with its declared UTF-8 encoding.
 * @param string $html Actual HTTP response body.
 * @return DOMXPath Queryable document for semantic presentation checks.
 */
function content_workflow_xpath(string $html): DOMXPath
{
    $document = new DOMDocument();
    @$document->loadHTML('<?xml encoding="UTF-8">' . $html);
    return new DOMXPath($document);
}

/**
 * Require successful rendering with matching document and authored languages.
 * @param array<string,mixed> $response Actual fixture HTTP response.
 * @param string $language Expected effective language.
 * @param string $title Expected authored title in rendered markup.
 * @return void Throws when the page or its title does not match.
 */
function content_workflow_page(array $response, string $language, string $title): void
{
    $xpath = content_workflow_xpath($response['body']);
    check($response['status'] === 200, 'Localized page did not render successfully (status ' . (int) $response['status'] . ').');
    check($xpath->query('//html[@lang="' . $language . '"]')->length === 1, 'Page interface language differs.');
    check(str_contains($response['body'], $title), 'Authored page title differs from the effective language.');
}

$stage = 'fixture validation';
$pdo = null;
$snapshots = [];
$exitStatus = 0;
try {
    $token = (string) getenv('GALLERY_WORKFLOW_TOKEN');
    $directory = validateFixture((string) getenv('GALLERY_WORKFLOW_FIXTURE'), $token);
    $pdo = fixtureDatabase($directory, $token);
    $seed = json_decode((string) file_get_contents($directory . '/seed.json'), true, 512, JSON_THROW_ON_ERROR);
    $origin = json_decode((string) file_get_contents($directory . '/endpoint.json'), true, 512, JSON_THROW_ON_ERROR)['url'];
    $rootId = (int) $seed['root_id'];
    $childId = (int) $seed['protected_id'];
    $rootPath = rawurlencode(row($pdo, 'SELECT folder_path FROM galleries WHERE id=?', [$rootId])['folder_path']);
    $childPath = rawurlencode(row($pdo, 'SELECT folder_path FROM galleries WHERE id=?', [$childId])['folder_path']);
    $snapshots['galleries'] = $pdo->query('SELECT * FROM galleries')->fetchAll(PDO::FETCH_ASSOC);
    $snapshots['images'] = $pdo->query('SELECT * FROM images')->fetchAll(PDO::FETCH_ASSOC);
    foreach (['app_settings', 'gallery_translations', 'image_translations'] as $table) {
        $snapshots[$table] = $pdo->query('SELECT * FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC);
    }
    $set = $pdo->prepare('INSERT INTO app_settings (setting_key, setting_value, updated_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_at=VALUES(updated_at)');
    foreach (['public_language' => 'en', 'public_language_selector_enabled' => '1', 'public_language_selector_languages' => '["en","cs","de","sv"]',
        'theme_favorite_gallery_ids' => json_encode([$rootId]), 'feature_flag.multilingual_content.enabled' => '1',
        'pagination_enabled' => '1', 'pagination_columns' => '1', 'pagination_rows' => '1',
        'thumbnail_compatibility_mode' => 'legacy'] as $key => $value) {
        $set->execute([$key, $value]);
    }
    $galleryUpdate = $pdo->prepare("UPDATE galleries SET title=?, description=?, content_language='en', gps_map_enabled=1, updated_at=NOW() WHERE id=?");
    foreach ([$rootId, $childId] as $id) {
        $galleryUpdate->execute(['93 source gallery ' . $id, '93 source description ' . $id, $id]);
        foreach (['cs', 'de', 'sv'] as $language) {
            $pdo->prepare('INSERT INTO gallery_translations (gallery_id, language_code, title, description, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE title=VALUES(title), description=VALUES(description)')
                ->execute([$id, $language, '93 ' . $language . ' gallery ' . $id, '93 ' . $language . ' description ' . $id]);
        }
    }
    // Other central fixtures may add earlier siblings. Keep this known card on
    // the first one-item page, then restore its original ordering in finally.
    $firstSiblingOrder = (int) row($pdo, 'SELECT COALESCE(MIN(sort_order), 0) AS sort_order FROM galleries WHERE parent_id=?', [$rootId])['sort_order'];
    $pdo->prepare('UPDATE galleries SET sort_order=? WHERE id=?')->execute([$firstSiblingOrder - 10, $childId]);
    $images = $pdo->query('SELECT id FROM images WHERE gallery_id=' . $rootId . ' ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    check(count($images) >= 2, 'Localization fixture requires two seeded photos.');
    foreach ($images as $id) {
        $pdo->prepare("UPDATE images SET title=?, description=?, content_language='en', gps_lat=50.0, gps_lng=14.0, updated_at=NOW() WHERE id=?")
            ->execute(['93 source photo ' . $id, '93 source caption ' . $id, $id]);
    }
    foreach (['cs', 'de', 'sv'] as $language) {
        $pdo->prepare('INSERT INTO image_translations (image_id, language_code, title, description, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE title=VALUES(title), description=VALUES(description)')
            ->execute([$images[1], $language, '93 ' . $language . ' photo', '93 ' . $language . ' caption']);
    }
    $pdo->prepare('UPDATE galleries SET cover_image_id=? WHERE id=?')->execute([$images[1], $rootId]);

    $anonymous = new Http($origin);
    $admin = new Http($origin);
    $csrf = $admin->login($seed);
    $stage = 'authorized social preview thumbnail';
    check($anonymous->request('/index.php?page=thumb&id=' . $images[1] . '&size=300&format=jpg')['status'] === 200,
        'Social preview thumbnail did not render.');
    $stage = 'public home metadata and visitor cookie';
    $home = $anonymous->request('/index.php?page=home&lang=de');
    content_workflow_page($home, 'de', '93 de gallery ' . $rootId);
    check(str_contains($home['body'], '93 de description ' . $rootId), 'Home card description did not localize.');
    $favoriteText = content_workflow_xpath($home['body'])->query('//a[contains(@class,"nav-favorite-gallery")]')->item(0)?->textContent ?? '';
    check($favoriteText === '93 de gallery ' . $rootId, 'Favorite navigation did not localize.');
    check($anonymous->cookieValue('cms_public_language') === 'de', 'Public language cookie was not persisted.');
    $stage = 'public gallery metadata and source fallback';
    $gallery = $anonymous->request('/index.php?page=gallery&gallery_path=' . $rootPath);
    content_workflow_page($gallery, 'de', '93 de gallery ' . $rootId);
    $xpath = content_workflow_xpath($gallery['body']);
    check(str_contains($xpath->query('//title')->item(0)?->textContent ?? '', '93 de gallery ' . $rootId), 'SEO title did not localize.');
    check(str_contains($xpath->query('//meta[@name="description"]')->item(0)?->getAttribute('content') ?? '', '93 de description ' . $rootId), 'SEO description did not localize.');
    check(str_contains($gallery['body'], '93 source caption ' . $images[0]), 'Absent photo translation did not fall back to source.');
    check(str_contains($gallery['body'], '93 de gallery ' . $childId), 'Physical subgallery title did not localize.');
    $stage = 'social preview outside the selected photo page';
    check(($xpath->query('//meta[@property="og:image:alt"]')->item(0)?->getAttribute('content') ?? '') === '93 de caption',
        'Out-of-page social preview caption did not localize.');
    check(($xpath->query('//meta[@name="twitter:image:alt"]')->item(0)?->getAttribute('content') ?? '') === '93 de caption',
        'Twitter preview caption did not localize.');
    $photoPage = $anonymous->request('/index.php?page=gallery&gallery_path=' . $rootPath . '&photo_page=2');
    content_workflow_page($photoPage, 'de', '93 de gallery ' . $rootId);
    check(str_contains($photoPage['body'], '93 de caption'), 'Photo page caption did not localize.');

    $stage = 'GPS map cache language isolation';
    $map = $anonymous->request('/index.php?page=gallery_map_data&id=' . $rootId);
    check($map['status'] === 200 && ($map['json']['title'] ?? '') === '93 de gallery ' . $rootId, 'Map gallery title did not localize.');
    check(str_contains($map['body'], '93 de caption'), 'Map popup caption did not localize.');
    $anonymous->request('/index.php?page=home&lang=sv');
    $swedishMap = $anonymous->request('/index.php?page=gallery_map_data&id=' . $rootId);
    check(str_contains($swedishMap['body'], '93 sv caption') && !str_contains($swedishMap['body'], '93 de caption'), 'Map reused another language cache.');

    $stage = 'persisted Admin language and source editor safety';
    $saved = $admin->request('/index.php?page=admin_theme', ['csrf_token' => $csrf, 'cms_language' => 'cs', 'theme_controls_changed' => '0']);
    check($saved['status'] === 302 && $admin->cookieValue('cms_admin_language') === 'cs', 'Admin language preference was not saved.');
    $fragment = $admin->request('/index.php?page=admin_dashboard_fragment&surface=galleries', null, true);
    check($fragment['status'] === 200 && str_contains($fragment['json']['html'] ?? '', '93 cs gallery ' . $rootId)
        && str_contains($fragment['json']['html'] ?? '', '93 cs gallery ' . $childId), 'Admin workspace did not use its language preference.');
    $editor = $admin->request('/index.php?page=admin_edit_gallery&id=' . $rootId);
    content_workflow_page($editor, 'cs', '93 cs gallery ' . $rootId);
    $editorXpath = content_workflow_xpath($editor['body']);
    check(($editorXpath->query('//input[@name="title"]')->item(0)?->getAttribute('value') ?? '') === '93 source gallery ' . $rootId,
        'Editor replaced the source title with presentation text.');
    check(($editorXpath->query('//textarea[@name="description"]')->item(0)?->textContent ?? '') === '93 source description ' . $rootId,
        'Editor replaced the source description with presentation text.');
    $stage = 'nested public gallery with independent Admin preference';
    $nested = $admin->request('/index.php?page=gallery&gallery_path=' . $childPath . '&lang=de');
    content_workflow_page($nested, 'de', '93 de gallery ' . $childId);
    $breadcrumbText = content_workflow_xpath($nested['body'])->query('//nav[contains(@class,"breadcrumbs")]')->item(0)?->textContent ?? '';
    check(str_contains($breadcrumbText, '93 de gallery ' . $rootId) && str_contains($breadcrumbText, '93 de gallery ' . $childId), 'Nested breadcrumb languages differ.');
    content_workflow_page($admin->request('/index.php?page=admin_edit_gallery&id=' . $rootId), 'cs', '93 cs gallery ' . $rootId);
    $stage = 'default reset and unchanged persistence';
    content_workflow_page($anonymous->request('/index.php?page=home&lang=default'), 'en', '93 source gallery ' . $rootId);
    check(row($pdo, 'SELECT title FROM galleries WHERE id=?', [$rootId])['title'] === '93 source gallery ' . $rootId, 'Presentation changed stored source metadata.');
    echo "PASS gallery workflow metadata language public Admin source fallback and cache\n";
} catch (Throwable $exception) {
    $diagnostic = basename($exception->getFile()) === 'gallery_workflow_safety.php' ? ': ' . $exception->getMessage() : ' line ' . $exception->getLine();
    fwrite(STDERR, 'FAIL gallery workflow metadata language at ' . $stage . $diagnostic . "\n");
    $exitStatus = 1;
} finally {
    if ($pdo instanceof PDO && $snapshots !== []) {
        foreach (['galleries', 'images'] as $table) {
            foreach ($snapshots[$table] ?? [] as $original) {
                $id = $original['id'];
                unset($original['id']);
                $assignments = implode(', ', array_map(static fn (string $column): string => $column . '=?', array_keys($original)));
                $pdo->prepare('UPDATE ' . $table . ' SET ' . $assignments . ' WHERE id=?')->execute([...array_values($original), $id]);
            }
        }
        foreach (['app_settings', 'gallery_translations', 'image_translations'] as $table) {
            if (!array_key_exists($table, $snapshots)) {
                continue;
            }
            $pdo->exec('DELETE FROM ' . $table);
            foreach ($snapshots[$table] ?? [] as $original) {
                $columns = implode(', ', array_keys($original));
                $marks = implode(', ', array_fill(0, count($original), '?'));
                $pdo->prepare('INSERT INTO ' . $table . ' (' . $columns . ') VALUES (' . $marks . ')')->execute(array_values($original));
            }
        }
    }
}
exit($exitStatus);
