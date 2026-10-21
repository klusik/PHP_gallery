<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/image_quick_tag_workflow_test.php
 * Module Type: Regression Test
 * Purpose: Verify targeted quick photo-tag mutations and production rollback behavior.
 * Responsibilities:
 *   - Protect authorization, CSRF, stale-source and media-category boundaries.
 *   - Verify targeted changes preserve concurrent assignments and image metadata.
 *   - Exercise the production tag model rollback without privileged database DDL.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once __DIR__ . '/support/gallery_workflow_http.php';
require_once __DIR__ . '/support/image_quick_tag_failure_model.php';

use GalleryWorkflow\Http;
use function GalleryWorkflow\check;
use function GalleryWorkflow\envelope;
use function GalleryWorkflow\fixtureDatabase;
use function GalleryWorkflow\row;
use function GalleryWorkflow\validateFixture;

/**
 * Return the direct tag IDs currently assigned to one physical image.
 *
 * @param PDO $pdo Connection to the owned disposable workflow database.
 * @param int $imageId Stable image identifier.
 * @return list<int> Sorted direct tag identifiers.
 */
function imageQuickTagIds(PDO $pdo, int $imageId): array
{
    $statement = $pdo->prepare(
        'SELECT tag_id FROM image_tags WHERE image_id = ? ORDER BY tag_id',
    );
    $statement->execute([$imageId]);
    return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Prove the real tag model rolls back a tag row when its later assignment write fails.
 * @return void Throws when the production model commits partial tag state.
 */
function imageQuickTagAssertModelRollback(): void
{
    // Fixture identities 77/88 and existing tag 20 make the owner and
    // unrelated-assignment preservation boundary explicit and deterministic.
    $connection = new ImageQuickTagFailurePdo();
    $GLOBALS['image_quick_tag_failure_connection'] = $connection;
    $initialTags = $connection->tags;
    $initialAssignments = $connection->assignments;
    $failure = null;
    try {
        \Gallery\Models\tag_model_mutate_image_tag_atomically(
            77,
            88,
            'add',
            null,
            [
                'name' => 'Uncommitted image tag',
                'slug_candidates' => ['uncommitted-image-tag'],
                'created_at' => '2030-01-01 00:00:00',
            ],
            true,
        );
    } catch (PDOException $exception) {
        $failure = $exception;
    } finally {
        unset($GLOBALS['image_quick_tag_failure_connection']);
    }
    check(
        $failure instanceof PDOException
            && $connection->events === ['begin', 'rollback']
            && $connection->tags === $initialTags
            && $connection->assignments === $initialAssignments,
        'The production tag model must roll back the inserted tag and preserve preexisting assignments after its later image_tags write fails.',
    );
}

imageQuickTagAssertModelRollback();
echo "PASS quick photo-tag production model rollback\n";

if (!getenv('GALLERY_WORKFLOW_FIXTURE')) {
    $required = getenv('GALLERY_WORKFLOW_REQUIRED') === '1';
    echo
        ($required ? 'BLOCKED' : 'SKIP')
            . " quick image tag workflow requires disposable runner\n";
    exit($required ? 1 : 0);
}

$stage = 'fixture validation';
$testTagIds = [];
$testTagSlugs = [];
$imageId = 0;
$imageSnapshot = [];
$originalImageGallery = 0;
$originalPath = '';
$originalPathHash = '';
$inlineSetting = null;
$inlineSettingExists = false;
$inlineSettingCaptured = false;
$failureMessage = null;
try {
    $token = (string) getenv('GALLERY_WORKFLOW_TOKEN');
    $directory = validateFixture(
        (string) getenv('GALLERY_WORKFLOW_FIXTURE'),
        $token,
    );
    $pdo = fixtureDatabase($directory, $token);
    $seed = json_decode(
        (string) file_get_contents($directory . '/seed.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $origin = json_decode(
        (string) file_get_contents($directory . '/endpoint.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    )['url'];
    $image = row(
        $pdo,
        'SELECT id, gallery_id, relative_path, relative_path_hash,
                filename, title, description, visibility, updated_at
         FROM images WHERE gallery_id = ? ORDER BY id LIMIT 1',
        [(int) $seed['root_id']],
    );
    check($image !== [], 'Quick-tag fixture image is missing.');
    $imageId = (int) $image['id'];
    $originalImageGallery = (int) $image['gallery_id'];
    $originalPath = (string) $image['relative_path'];
    $originalPathHash = (string) $image['relative_path_hash'];
    $imageSnapshot = [
        'filename' => (string) $image['filename'],
        'title' => $image['title'],
        'description' => $image['description'],
        'visibility' => (string) $image['visibility'],
        'updated_at' => (string) $image['updated_at'],
    ];
    $admin = new Http($origin);
    $anonymous = new Http($origin);
    $csrf = $admin->login($seed);
    $route = '/index.php?page=admin_public_update_image';
    $baseFields = [
        'csrf_token' => $csrf,
        'image_id' => $imageId,
        'source_gallery_id' => $originalImageGallery,
    ];

    $stage = 'anonymous and CSRF rejection';
    $anonymousResponse = $anonymous->request(
        $route,
        $baseFields + [
            'action' => 'quick_tag_add',
            'tag_name' => 'quick-workflow-anonymous-' . $token,
        ],
        true,
    );
    check(
        $anonymousResponse['status'] === 401,
        'Anonymous quick-tag mutation must be unauthorized.',
    );
    $csrfResponse = $admin->request(
        $route,
        array_replace($baseFields, [
            'csrf_token' => 'invalid',
            'action' => 'quick_tag_add',
            'tag_name' => 'quick-workflow-csrf-' . $token,
        ]),
        true,
    );
    check(
        $csrfResponse['status'] === 400,
        'Invalid quick-tag CSRF token must be rejected.',
    );
    check(
        (int) $pdo->query('SELECT COUNT(*) FROM tags WHERE name LIKE '
            . $pdo->quote('quick-workflow-%-' . $token))->fetchColumn() === 0,
        'Rejected quick-tag requests must not create tags.',
    );

    $stage = 'inline capability rejection';
    $settingKey = 'feature_flag.inline_administration.enabled';
    $setting = row(
        $pdo,
        'SELECT setting_value FROM app_settings WHERE setting_key = ?',
        [$settingKey],
    );
    $inlineSettingExists = $setting !== [];
    $inlineSetting = $inlineSettingExists
        ? $setting['setting_value']
        : null;
    $inlineSettingCaptured = true;
    $pdo->prepare(
        'INSERT INTO app_settings (setting_key, setting_value, updated_at)
         VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE
         setting_value = VALUES(setting_value), updated_at = VALUES(updated_at)',
    )->execute([$settingKey, '0']);
    $disabledResponse = $admin->request(
        $route,
        $baseFields + [
            'action' => 'quick_tag_add',
            'tag_name' => 'quick-workflow-disabled-' . $token,
        ],
        true,
    );
    check(
        $disabledResponse['status'] === 403,
        'Quick-tag mutation must honor the disabled inline capability.',
    );
    if (!$inlineSettingExists) {
        $pdo->prepare('DELETE FROM app_settings WHERE setting_key = ?')
            ->execute([$settingKey]);
    } else {
        $pdo->prepare(
            'UPDATE app_settings SET setting_value = ?, updated_at = NOW()
             WHERE setting_key = ?',
        )->execute([$inlineSetting, $settingKey]);
    }
    $stage = 'stale physical source rejection';
    $pdo->prepare(
        'UPDATE images SET gallery_id = ?, relative_path = ?,
         relative_path_hash = SHA2(?, 256) WHERE id = ?',
    )->execute([
        (int) $seed['protected_id'],
        'quick-tag-moved-' . $token . '.jpg',
        'quick-tag-moved-' . $token . '.jpg',
        $imageId,
    ]);
    $staleResponse = $admin->request(
        $route,
        $baseFields + [
            'action' => 'quick_tag_add',
            'tag_name' => 'quick-workflow-moved-' . $token,
        ],
        true,
    );
    check(
        $staleResponse['status'] === 409,
        'A photo moved to another physical gallery must reject its stale source lease.',
    );
    $pdo->prepare(
        'UPDATE images SET gallery_id = ?, relative_path = ?,
         relative_path_hash = ? WHERE id = ?',
    )->execute([
        $originalImageGallery,
        $originalPath,
        $originalPathHash,
        $imageId,
    ]);

    $stage = 'targeted add, duplicate and interleaved-link preservation';
    // Capture the browser's pre-request snapshot before the independent write.
    $beforeIds = imageQuickTagIds($pdo, $imageId);
    $otherTagName = 'quick-workflow-preserved-' . $token;
    $testTagSlugs[] = 'quick-preserved-' . $token;
    $pdo->prepare(
        'INSERT INTO tags (name, slug, media_type, created_at, updated_at)
         VALUES (?, ?, \'image\', NOW(), NOW())',
    )->execute([$otherTagName, 'quick-preserved-' . $token]);
    $otherTagId = (int) $pdo->lastInsertId();
    $testTagIds[] = $otherTagId;
    $pdo->prepare(
        'INSERT INTO image_tags (image_id, tag_id) VALUES (?, ?)',
    )->execute([$imageId, $otherTagId]);
    $tagName = 'Quick Workflow ' . $token;
    $canonicalTagName = 'quick-workflow-' . $token;
    $testTagSlugs[] = 'quick-workflow-' . $token;
    // Model another editor committing after this browser loaded its photo state.
    $interleavedIds = imageQuickTagIds($pdo, $imageId);
    $addResponse = $admin->request(
        $route,
        $baseFields + [
            'action' => 'quick_tag_add',
            'tag_name' => $tagName,
        ],
        true,
    );
    $added = envelope($addResponse, 'Quick photo-tag add');
    $tag = row(
        $pdo,
        'SELECT id, name, media_type FROM tags WHERE name = ?',
        [$canonicalTagName],
    );
    check($tag !== [], 'Quick photo-tag add did not create its shared tag.');
    $tagId = (int) $tag['id'];
    $testTagIds[] = $tagId;
    $expectedIds = $interleavedIds;
    $expectedIds[] = $tagId;
    sort($expectedIds);
    check(
        $tag['media_type'] === 'image'
            && imageQuickTagIds($pdo, $imageId) === $expectedIds,
        'Quick add must create one image tag and preserve existing direct assignments.',
    );
    check(
        in_array($otherTagId, $beforeIds, true) === false
            && in_array($otherTagId, $interleavedIds, true),
        'The independent assignment must land after the browser snapshot and before targeted mutation.',
    );
    check(
        ($added['mutation']['type'] ?? '') === 'image.tag'
            && ($added['mutation']['entity_ids'] ?? []) === [$imageId]
            && ($added['changed_tag']['id'] ?? 0) === $tagId
            && ($added['already_present'] ?? true) === false
            && ($added['direct_tags'] ?? null) !== null
            && isset($added['contexts'][0]['postcondition'])
            && ($added['contexts'][0]['postcondition']['type'] ?? '')
                === 'image_tags'
            && ($added['contexts'][0]['postcondition']['image_id'] ?? 0)
                === $imageId
            && in_array(
                $tagId,
                $added['contexts'][0]['postcondition']['tag_ids'] ?? [],
                true,
            ),
        'Quick add must return the authoritative image.tag envelope and tag postcondition.',
    );
    $duplicateResponse = $admin->request(
        $route,
        $baseFields + [
            'action' => 'quick_tag_add',
            'tag_id' => $tagId,
        ],
        true,
    );
    $duplicate = envelope($duplicateResponse, 'Duplicate quick photo-tag add');
    check(
        ($duplicate['already_present'] ?? false) === true
            && imageQuickTagIds($pdo, $imageId) === $expectedIds,
        'Duplicate quick add must be idempotent and preserve other direct tags.',
    );

    $stage = 'wrong media-type rejection';
    $testTagSlugs[] = 'quick-music-' . $token;
    $pdo->prepare(
        'INSERT INTO tags (name, slug, media_type, created_at, updated_at)
         VALUES (?, ?, \'music\', NOW(), NOW())',
    )->execute([
        'quick-workflow-music-' . $token,
        'quick-music-' . $token,
    ]);
    $musicTagId = (int) $pdo->lastInsertId();
    $testTagIds[] = $musicTagId;
    $wrongTypeResponse = $admin->request(
        $route,
        $baseFields + [
            'action' => 'quick_tag_add',
            'tag_id' => $musicTagId,
        ],
        true,
    );
    check(
        $wrongTypeResponse['status'] === 422
            && imageQuickTagIds($pdo, $imageId) === $expectedIds,
        'Music tag identifiers must be rejected without changing image assignments.',
    );

    $stage = 'targeted remove and unrelated metadata preservation';
    $removeResponse = $admin->request(
        $route,
        $baseFields + [
            'action' => 'quick_tag_remove',
            'tag_id' => $tagId,
        ],
        true,
    );
    $removed = envelope($removeResponse, 'Quick photo-tag remove');
    check(
        !in_array($tagId, imageQuickTagIds($pdo, $imageId), true)
            && in_array($otherTagId, imageQuickTagIds($pdo, $imageId), true)
            && ($removed['contexts'][0]['postcondition']['type'] ?? '')
                === 'image_tags',
        'Targeted removal must preserve unrelated direct assignments.',
    );
    $afterImage = row(
        $pdo,
        'SELECT gallery_id, filename, title, description, visibility, updated_at
         FROM images WHERE id = ?',
        [$imageId],
    );
    check(
        (int) $afterImage['gallery_id'] === $originalImageGallery
            && (string) $afterImage['filename'] === $imageSnapshot['filename']
            && $afterImage['title'] === $imageSnapshot['title']
            && $afterImage['description'] === $imageSnapshot['description']
            && (string) $afterImage['visibility'] === $imageSnapshot['visibility']
            && (string) $afterImage['updated_at'] === $imageSnapshot['updated_at'],
        'Quick tagging must not change unrelated photo metadata.',
    );
    echo "PASS quick photo-tag auth, source, targeted persistence and envelope\n";
} catch (Throwable $exception) {
    $failureMessage = 'FAIL quick image tag workflow at ' . $stage . ': '
        . $exception->getMessage();
} finally {
    if (isset($pdo) && $pdo instanceof PDO) {
        if ($testTagSlugs !== []) {
            $slugMarks = implode(',', array_fill(0, count($testTagSlugs), '?'));
            $slugQuery = $pdo->prepare(
                'SELECT id FROM tags WHERE slug IN (' . $slugMarks . ')',
            );
            $slugQuery->execute($testTagSlugs);
            $testTagIds = array_values(array_unique(array_merge(
                $testTagIds,
                array_map('intval', $slugQuery->fetchAll(PDO::FETCH_COLUMN)),
            )));
        }
        if ($testTagIds !== []) {
            if ($imageId > 0) {
                $marks = implode(',', array_fill(0, count($testTagIds), '?'));
                $pdo->prepare(
                    'DELETE FROM image_tags WHERE image_id = ? AND tag_id IN ('
                        . $marks
                        . ')',
                )->execute([$imageId, ...$testTagIds]);
            }
            $marks = implode(',', array_fill(0, count($testTagIds), '?'));
            $pdo->prepare('DELETE FROM tags WHERE id IN (' . $marks . ')')
                ->execute($testTagIds);
        }
        if ($imageId > 0 && $originalImageGallery > 0) {
            $pdo->prepare(
                'UPDATE images SET gallery_id = ?, relative_path = ?,
                 relative_path_hash = ? WHERE id = ?',
            )->execute([
                $originalImageGallery,
                $originalPath,
                $originalPathHash,
                $imageId,
            ]);
        }
        if ($inlineSettingCaptured) {
            $settingKey = 'feature_flag.inline_administration.enabled';
            if (!$inlineSettingExists) {
                $pdo->prepare('DELETE FROM app_settings WHERE setting_key = ?')
                    ->execute([$settingKey]);
            } else {
                $pdo->prepare(
                    'UPDATE app_settings SET setting_value = ?, updated_at = NOW()
                     WHERE setting_key = ?',
                )->execute([$inlineSetting, $settingKey]);
            }
        }
    }
}
if ($failureMessage !== null) {
    fwrite(STDERR, $failureMessage . "\n");
    exit(1);
}
