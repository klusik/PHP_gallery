<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_workflow_integration_test.php
 * Module Type: Regression Test
 * Purpose: Exercise authenticated workflows against disposable application data.
 * Responsibilities:
 *   - Verify HTTP outcomes against database and original-file postconditions.
 * Author: Rudolf Klusal
 * Real authenticated HTTP journeys with database and original-file postconditions.
 */
declare(strict_types=1);

require_once __DIR__ . '/support/gallery_workflow_http.php';

use GalleryWorkflow\Http;
use function GalleryWorkflow\check;
use function GalleryWorkflow\countRows;
use function GalleryWorkflow\envelope;
use function GalleryWorkflow\fixtureDatabase;
use function GalleryWorkflow\row;
use function GalleryWorkflow\validateFixture;

if (!getenv('GALLERY_WORKFLOW_FIXTURE')) {
    $required = getenv('GALLERY_WORKFLOW_REQUIRED') === '1';
    echo ($required ? 'BLOCKED' : 'SKIP') . " gallery workflow real database and HTTP require disposable runner\n";
    exit($required ? 1 : 0);
}
$stage = 'fixture validation';
try {
    $token = (string) getenv('GALLERY_WORKFLOW_TOKEN');
    $directory = validateFixture((string) getenv('GALLERY_WORKFLOW_FIXTURE'), $token);
    $pdo = fixtureDatabase($directory, $token);
    $seed = json_decode((string) file_get_contents($directory . '/seed.json'), true, 512, JSON_THROW_ON_ERROR);
    $origin = json_decode((string) file_get_contents($directory . '/endpoint.json'), true, 512, JSON_THROW_ON_ERROR)['url'];
    $admin = new Http($origin);
    $anonymous = new Http($origin);
    $stage = 'login and negative mutation controls';
    $csrf = $admin->login($seed);
    $baseline = countRows($pdo, 'galleries');
    $fields = ['csrf_token' => $csrf, 'title' => 'HTTP workflow', 'folder_name' => 'http-workflow',
        'parent_id' => $seed['root_id'], 'visibility' => 'public', 'panel' => '1',
        'operation_key' => $admin->operationKey('/index.php?page=admin_new_gallery&panel=1')];
    check($anonymous->request('/index.php?page=admin_new_gallery', $fields, true)['status'] === 401, 'Anonymous creation must fail.');
    check($admin->request('/index.php?page=admin_new_gallery', array_replace($fields, ['csrf_token' => 'invalid']), true)['status'] === 400,
        'Invalid CSRF creation must fail.');
    check(countRows($pdo, 'galleries') === $baseline && !is_dir($directory . '/galleries/seed/http-workflow'), 'Rejected create changed persistence.');
    echo "PASS gallery workflow login auth and CSRF controls\n";

    $stage = 'HTTP create persistence';
    $created = envelope($admin->request('/index.php?page=admin_new_gallery', $fields, true), 'Create');
    $id = (int) $created['gallery_id'];
    $gallery = row($pdo, 'SELECT * FROM galleries WHERE id = ?', [$id]);
    check($gallery['title'] === $fields['title'] && (int) $gallery['parent_id'] === $seed['root_id']
        && $gallery['visibility'] === 'public' && countRows($pdo, 'galleries') === $baseline + 1, 'Created row mismatch.');
    $folder = $directory . '/galleries/' . $gallery['folder_path'];
    check(is_file($folder . '/gallery.json'), 'Create sidecar missing.');
    echo "PASS gallery workflow HTTP create database and sidecar\n";

    $stage = 'classic multipart upload';
    $sample = $directory . '/galleries/seed/sample-1.jpg';
    $upload = ['csrf_token' => $csrf, 'gallery_id' => (string) $id, 'upload_mode' => 'existing',
        'images[]' => new CURLFile($sample, 'image/jpeg', 'uploaded.jpg'),
        'operation_key' => $admin->operationKey('/index.php?page=admin_upload&gallery_id=' . $id)];
    $beforeImages = countRows($pdo, 'images');
    check($anonymous->request('/index.php?page=admin_upload', $upload, true)['status'] === 401, 'Anonymous upload must fail.');
    check($admin->request('/index.php?page=admin_upload', array_replace($upload, ['csrf_token' => 'invalid']), true)['status'] === 400,
        'Invalid CSRF upload must fail.');
    check(countRows($pdo, 'images') === $beforeImages, 'Rejected upload changed image rows.');
    envelope($admin->request('/index.php?page=admin_upload', $upload, true), 'Upload');
    $image = row($pdo, 'SELECT * FROM images WHERE gallery_id = ?', [$id]);
    check(countRows($pdo, 'images') === $beforeImages + 1 && (int) $image['width'] === 48 && (int) $image['height'] === 32, 'Upload persistence mismatch.');
    $original = $folder . '/' . $image['relative_path'];
    check(is_file($original) && hash_file('sha256', $original) === hash_file('sha256', $sample), 'Uploaded original hash mismatch.');
    echo "PASS gallery workflow multipart upload metadata and original hash\n";

    $stage = 'missing-folder catalog preservation';
    $childFields = array_replace($fields, ['title' => 'HTTP nested', 'folder_name' => 'nested', 'parent_id' => $id,
        'operation_key' => $admin->operationKey('/index.php?page=admin_new_gallery&panel=1')]);
    $childId = (int) envelope($admin->request('/index.php?page=admin_new_gallery', $childFields, true), 'Child')['gallery_id'];
    $catalogBefore = row($pdo, 'SELECT * FROM galleries WHERE id = ?', [$id]);
    $childBefore = row($pdo, 'SELECT * FROM galleries WHERE id = ?', [$childId]);
    $imageBefore = row($pdo, 'SELECT * FROM images WHERE id = ?', [$image['id']]);
    $galleryCountBefore = countRows($pdo, 'galleries');
    // Both paths are beneath the validated disposable fixture; never touch the live site.
    $displaced = $directory . '/displaced-gallery';
    check(!file_exists($displaced) && rename($folder, $displaced), 'Could not displace the owned fixture folder.');
    try {
        $conflict = $admin->request('/index.php?page=admin_new_gallery', array_replace($fields, [
            'operation_key' => $admin->operationKey('/index.php?page=admin_new_gallery&panel=1'),
        ]), true);
        $conflictBody = json_decode($conflict['body'], true, 512, JSON_THROW_ON_ERROR);
        check($conflict['status'] === 409 && empty($conflictBody['ok'])
            && ($conflictBody['error_code'] ?? '') === 'gallery_catalog_conflict', 'Missing-folder create must return a typed conflict.');
        check(row($pdo, 'SELECT * FROM galleries WHERE id = ?', [$id]) === $catalogBefore
            && row($pdo, 'SELECT * FROM galleries WHERE id = ?', [$childId]) === $childBefore
            && row($pdo, 'SELECT * FROM images WHERE id = ?', [$image['id']]) === $imageBefore
            && countRows($pdo, 'galleries') === $galleryCountBefore, 'Refused creation changed the original catalog subtree.');
        check(!is_dir($folder) && hash_file('sha256', $displaced . '/' . $image['relative_path']) === hash_file('sha256', $sample),
            'Refused creation changed storage or lost the recoverable original.');
    } finally {
        check(!file_exists($folder) && rename($displaced, $folder), 'Could not restore the owned fixture folder.');
    }
    echo "PASS gallery workflow missing-folder create preserves subtree and original\n";

    $stage = 'prepared upload lost response retry';
    $session = 'workflow-' . $token;
    $archive = $directory . '/batch.zip';
    $zip = new ZipArchive();
    check($zip->open($archive, ZipArchive::CREATE | ZipArchive::EXCL) === true, 'Could not create prepared upload fixture.');
    $zip->addFromString('manifest.json', json_encode(['upload_session_id' => $session, 'batch_index' => 0,
        'items' => [['original_name' => 'retry.jpg', 'prepared_name' => 'retry.jpg', 'original_path' => 'originals/retry.jpg',
            'original_mime' => 'image/jpeg', 'original_width' => 48, 'original_height' => 32, 'source_index' => 0, 'variants' => []]]], JSON_THROW_ON_ERROR));
    $zip->addFile($sample, 'originals/retry.jpg');
    $zip->setCompressionName('manifest.json', ZipArchive::CM_STORE);
    $zip->setCompressionName('originals/retry.jpg', ZipArchive::CM_STORE);
    $zip->close();
    $batch = ['csrf_token' => $csrf, 'gallery_id' => (string) $id, 'upload_session_id' => $session,
        'batch_index' => '0', 'total_batches' => '1', 'zip_batch' => new CURLFile($archive, 'application/zip', 'batch.zip')];
    // Intentionally discard the accepted response: the retry gets no client-side result from the first call.
    $admin->request('/index.php?page=admin_upload_browser_batch', $batch, true);
    check(countRows($pdo, 'images') === $beforeImages + 2, 'First prepared batch did not persist exactly one image.');
    $accepted = row($pdo, 'SELECT id, relative_path FROM images WHERE gallery_id = ? ORDER BY id DESC LIMIT 1', [$id]);
    envelope($admin->request('/index.php?page=admin_upload_browser_batch', $batch, true), 'Retry');
    check(countRows($pdo, 'images') === $beforeImages + 2, 'Retry duplicated upload rows.');
    check(row($pdo, 'SELECT id, relative_path FROM images WHERE gallery_id = ? ORDER BY id DESC LIMIT 1', [$id]) === $accepted,
        'Retry changed upload identity.');
    check(count(glob($folder . '/*.jpg') ?: []) === 2, 'Retry duplicated original files.');
    echo "PASS gallery workflow prepared upload retry preserves identity and count\n";

    $stage = 'edit visibility and protected media';
    $edit = ['csrf_token' => $csrf, 'id' => $id, 'title' => 'HTTP edited', 'description' => 'Persisted workflow description',
        'parent_id' => $seed['root_id'], 'slug' => $gallery['slug'], 'visibility' => 'unpublished',
        'edit_revision' => (string) row($pdo, 'SELECT edit_revision FROM galleries WHERE id = ?', [$id])['edit_revision']];
    check($anonymous->request('/index.php?page=admin_edit_gallery&id=' . $id, $edit, true)['status'] === 401, 'Anonymous edit must fail.');
    check($admin->request('/index.php?page=admin_edit_gallery&id=' . $id, array_replace($edit, ['csrf_token' => 'invalid']), true)['status'] === 400,
        'Invalid CSRF edit must fail.');
    check(row($pdo, 'SELECT title FROM galleries WHERE id = ?', [$id])['title'] === $fields['title'], 'Rejected edit changed title.');
    envelope($admin->request('/index.php?page=admin_edit_gallery&id=' . $id, $edit, true), 'Edit');
    $updated = row($pdo, 'SELECT * FROM galleries WHERE id = ?', [$id]);
    check($updated['title'] === $edit['title'] && $updated['description'] === $edit['description'] && $updated['visibility'] === 'unpublished',
        'Edited database values mismatch.');
    $sidecar = json_decode((string) file_get_contents($folder . '/gallery.json'), true, 512, JSON_THROW_ON_ERROR);
    check(($sidecar['title'] ?? '') === $edit['title'], 'Edited sidecar title mismatch.');
    // Unpublished is intentionally unlisted but accessible by direct URL in this CMS.
    check($anonymous->request('/index.php?page=media&id=' . $image['id'])['status'] === 200, 'Unpublished direct-access compatibility changed.');
    $edit['visibility'] = 'private';
    $edit['edit_revision'] = (string) $updated['edit_revision'];
    envelope($admin->request('/index.php?page=admin_edit_gallery&id=' . $id, $edit, true), 'Private');
    check(row($pdo, 'SELECT visibility FROM galleries WHERE id = ?', [$id])['visibility'] === 'private', 'Private visibility was not persisted.');
    check($anonymous->request('/index.php?page=media&id=' . $image['id'])['status'] === 404, 'Private original exposed.');
    $protectedImage = row($pdo, 'SELECT * FROM images WHERE gallery_id = ? LIMIT 1', [$seed['protected_id']]);
    foreach (['media', 'thumb'] as $route) {
        $denied = $anonymous->request('/index.php?page=' . $route . '&id=' . $protectedImage['id']);
        check(in_array($denied['status'], [302, 403, 404], true), 'Protected media did not deny anonymous access.');
    }
    $edit['visibility'] = 'public';
    $edit['edit_revision'] = (string) row($pdo, 'SELECT edit_revision FROM galleries WHERE id = ?', [$id])['edit_revision'];
    envelope($admin->request('/index.php?page=admin_edit_gallery&id=' . $id, $edit, true), 'Publish');
    $public = $anonymous->request('/index.php?page=media&id=' . $image['id']);
    check($public['status'] === 200 && hash('sha256', $public['body']) === hash_file('sha256', $original), 'Published original unavailable or incorrect.');
    echo "PASS gallery workflow edit publish unpublish and protected media authorization\n";

    $stage = 'recoverable subtree delete and restore';
    $beforeDelete = countRows($pdo, 'galleries');
    $delete = ['csrf_token' => $csrf, 'gallery_ids' => [$id], 'action' => 'delete'];
    check($admin->request('/index.php?page=admin_bulk_galleries', array_replace($delete, ['csrf_token' => 'invalid']), true)['status'] === 400,
        'Invalid CSRF delete must fail.');
    check($anonymous->request('/index.php?page=admin_bulk_galleries', $delete, true)['status'] === 401, 'Anonymous delete must fail.');
    check(countRows($pdo, 'galleries') === $beforeDelete && is_file($original), 'Rejected delete changed persistence.');
    check($admin->request('/index.php?page=admin_bulk_galleries', $delete)['status'] === 302, 'Delete fallback must redirect.');
    check(row($pdo, 'SELECT id FROM galleries WHERE id IN (?, ?)', [$id, $childId]) === [] && !is_dir($folder), 'Deleted subtree remains live.');
    $trash = row($pdo, "SELECT * FROM gallery_trash_entries WHERE status = 'trashed' ORDER BY id DESC LIMIT 1");
    check($trash !== [], 'Recoverable trash record missing.');
    check($anonymous->request('/index.php?page=media&id=' . $image['id'])['status'] !== 200, 'Deleted original remains public.');
    $restore = ['csrf_token' => $csrf, 'trash_token' => $trash['trash_token']];
    check($anonymous->request('/index.php?page=admin_trash_restore', $restore, true)['status'] === 401, 'Anonymous restore must fail.');
    check($admin->request('/index.php?page=admin_trash_restore', array_replace($restore, ['csrf_token' => 'invalid']), true)['status'] === 400,
        'Invalid CSRF restore must fail.');
    envelope($admin->request('/index.php?page=admin_trash_restore', $restore, true), 'Restore');
    $restored = row($pdo, 'SELECT * FROM galleries WHERE folder_path = ?', [$gallery['folder_path']]);
    check($restored['title'] === $edit['title'] && $restored['description'] === $edit['description'] && $restored['visibility'] === 'public', 'Restored gallery metadata mismatch.');
    $child = row($pdo, 'SELECT parent_id FROM galleries WHERE title = ?', ['HTTP nested']);
    check((int) $child['parent_id'] === (int) $restored['id'] && countRows($pdo, 'galleries') === $beforeDelete, 'Restored subtree relationship mismatch.');
    check(countRows($pdo, 'images') === $beforeImages + 2 && hash_file('sha256', $original) === hash_file('sha256', $sample), 'Restored original mismatch.');
    $admin->request('/index.php?page=admin_trash_restore', $restore, true);
    check(countRows($pdo, 'galleries') === $beforeDelete && countRows($pdo, 'images') === $beforeImages + 2, 'Repeat restore duplicated persistence.');
    echo "PASS gallery workflow trash subtree restoration and repeat control\n";

    $stage = 'expired session';
    $admin->request('/index.php?page=admin_logout', ['csrf_token' => $csrf]);
    check($admin->request('/index.php?page=admin_new_gallery', $fields, true)['status'] === 401, 'Logged-out session could still mutate.');
    check(countRows($pdo, 'galleries') === $beforeDelete, 'Expired-session mutation changed rows.');
    echo "PASS gallery workflow expired session rejects mutation\n";
} catch (Throwable $exception) {
    // No response bodies, SQL exceptions, cookies, configuration, or tokens reach audit/CI output.
    $detail = get_class($exception) === RuntimeException::class ? $exception->getMessage()
        : basename($exception->getFile()) . ' line ' . $exception->getLine();
    fwrite(STDERR, 'FAIL gallery workflow ' . $stage . ': ' . $detail . "\n");
    exit(1);
}
