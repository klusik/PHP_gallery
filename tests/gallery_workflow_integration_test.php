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

/**
 * Produce a small valid one-page PDF with deterministic cross-reference offsets.
 *
 * @return string Original PDF bytes used to verify unmodified HTTP streaming.
 */
function galleryWorkflowOfpFixturePdf(): string
{
    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $objects = [
        "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n",
        "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n",
        "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Contents 4 0 R >>\nendobj\n",
        "4 0 obj\n<< /Length 0 >>\nstream\n\nendstream\nendobj\n",
    ];
    $offsets = [];
    foreach ($objects as $object) {
        $offsets[] = strlen($pdf);
        $pdf .= $object;
    }
    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 5\n0000000000 65535 f \n";
    foreach ($offsets as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }
    return $pdf . "trailer\n<< /Root 1 0 R /Size 5 >>\nstartxref\n"
        . $xrefOffset . "\n%%EOF\n";
}

/**
 * Read the real rendered OFP URLs from a gallery page in the same HTTP session.
 *
 * @param Http $visitor The cookie-isolated anonymous HTTP client.
 * @param string $folderPath Physical gallery path in the migrated database.
 * @param int $galleryId Expected PDF-owning gallery ID.
 * @return array{inline:string,download:string} Root-relative public document links.
 */
function galleryWorkflowOfpLinks(Http $visitor, string $folderPath, int $galleryId): array
{
    $page = $visitor->request('/index.php?page=gallery&gallery_path=' . rawurlencode($folderPath));
    check($page['status'] === 200, 'A visitor cannot open the source gallery by its direct URL.');
    $document = new DOMDocument();
    check(@$document->loadHTML($page['body']), 'Real gallery HTML cannot be parsed.');
    $xpath = new DOMXPath($document);
    $inline = $xpath->query('//a[@data-simbrief-ofp-open]')->item(0);
    $download = $xpath->query('//a[@download="simbrief-ofp.pdf"]')->item(0);
    check($inline instanceof DOMElement && $download instanceof DOMElement,
        'Source gallery rendered without both OFP actions.');
    $expected = '/index.php?page=gallery_ofp_pdf&id=' . $galleryId;
    check($inline->getAttribute('href') === $expected
        && $download->getAttribute('href') === $expected . '&download=1',
        'The rendered OFP anchors do not identify the owner gallery or correct same-origin routes.');
    return ['inline' => $inline->getAttribute('href'), 'download' => $download->getAttribute('href')];
}

/**
 * Verify that a real runtime response streams the exact original PDF bytes.
 *
 * @param Http $visitor Cookie-isolated visitor context shared with the gallery view.
 * @param string $route Route rendered in the source gallery or its legacy alias.
 * @param string $expectedPdf Original persisted PDF file bytes.
 * @param bool $download Whether the response must use attachment disposition.
 * @return void Throws if the visitor cannot retrieve the original PDF.
 */
function galleryWorkflowOfpAssertPdf(Http $visitor, string $route, string $expectedPdf, bool $download = false): void
{
    $response = $visitor->request($route);
    check($response['status'] === 200
        && str_starts_with((string) ($response['headers']['content-type'] ?? ''), 'application/pdf')
        && str_starts_with((string) ($response['headers']['content-disposition'] ?? ''),
            $download ? 'attachment;' : 'inline;')
        && hash_equals($expectedPdf, $response['body']),
        'Real runtime OFP HTTP response is missing, not a PDF, or differs from the original file.');
}

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

    $stage = 'real runtime OFP public HTTP and anonymous preflight';
    $ofpBytes = galleryWorkflowOfpFixturePdf();
    check(file_put_contents($folder . '/simbrief-ofp.pdf', $ofpBytes) === strlen($ofpBytes),
        'Could not persist the original OFP bytes in the real fixture gallery.');
    check(file_put_contents($folder . '/simbrief-ofp-manifest.json', json_encode([
        'format' => 'php_gallery_simbrief_ofp_manifest_v1',
        'ofp_pdf_file' => 'simbrief-ofp.pdf',
    ], JSON_THROW_ON_ERROR)) !== false, 'Could not persist the real OFP attachment manifest.');
    $ofpLinks = galleryWorkflowOfpLinks($anonymous, (string) $gallery['folder_path'], $id);
    galleryWorkflowOfpAssertPdf($anonymous, $ofpLinks['inline'], $ofpBytes);
    galleryWorkflowOfpAssertPdf($anonymous, $ofpLinks['download'], $ofpBytes, true);
    $ofpHead = $anonymous->request($ofpLinks['inline'], null, false, 'HEAD');
    check($ofpHead['status'] === 200 && $ofpHead['body'] === ''
        && str_starts_with((string) ($ofpHead['headers']['content-type'] ?? ''), 'application/pdf')
        && (int) ($ofpHead['headers']['content-length'] ?? -1) === strlen($ofpBytes),
        'Real runtime OFP HEAD must expose PDF headers but no content.');
    galleryWorkflowOfpAssertPdf($anonymous, '/index.php?page=media&id=' . $id . '&ofp=1', $ofpBytes);
    galleryWorkflowOfpAssertPdf($anonymous, '/index.php?page=media&id=' . $id . '&ofp=1&download=1', $ofpBytes, true);
    $unexpectedOFP = $anonymous->request($ofpLinks['inline'] . '&unexpected=1');
    check($unexpectedOFP['status'] === 404 && $unexpectedOFP['body'] === "Not found.\n",
        'The real anonymous SEO guard must continue rejecting unknown query keys.');
    echo "PASS gallery workflow original OFP public GET/HEAD/download/legacy and SEO guard\n";


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

    $stage = 'real runtime unpublished OFP PDF against migrated gallery model';
    $unpublishedLinks = galleryWorkflowOfpLinks($anonymous, (string) $updated['folder_path'], $id);
    galleryWorkflowOfpAssertPdf($anonymous, $unpublishedLinks['inline'], $ofpBytes);
    galleryWorkflowOfpAssertPdf($anonymous, $unpublishedLinks['download'], $ofpBytes, true);
    $unpublishedHead = $anonymous->request($unpublishedLinks['inline'], null, false, 'HEAD');
    check($unpublishedHead['status'] === 200 && $unpublishedHead['body'] === ''
        && str_starts_with((string) ($unpublishedHead['headers']['content-type'] ?? ''), 'application/pdf')
        && (int) ($unpublishedHead['headers']['content-length'] ?? -1) === strlen($ofpBytes),
        'Unpublished direct-link OFP HEAD lost its original metadata.');
    galleryWorkflowOfpAssertPdf($anonymous, '/index.php?page=media&id=' . $id . '&ofp=1', $ofpBytes);
    galleryWorkflowOfpAssertPdf($anonymous, '/index.php?page=media&id=' . $id . '&ofp=1&download=1', $ofpBytes, true);
    echo "PASS gallery workflow unpublished OFP same-session gallery/GET/HEAD/download/legacy\n";

    $edit['visibility'] = 'private';
    $edit['edit_revision'] = (string) $updated['edit_revision'];
    envelope($admin->request('/index.php?page=admin_edit_gallery&id=' . $id, $edit, true), 'Private');
    check(row($pdo, 'SELECT visibility FROM galleries WHERE id = ?', [$id])['visibility'] === 'private', 'Private visibility was not persisted.');
    check($anonymous->request('/index.php?page=media&id=' . $image['id'])['status'] === 404, 'Private original exposed.');

    $stage = 'real runtime private OFP security boundary';
    foreach ([$unpublishedLinks['inline'], $unpublishedLinks['download'],
        '/index.php?page=media&id=' . $id . '&ofp=1'] as $privateRoute) {
        $privatePdf = $anonymous->request($privateRoute);
        check($privatePdf['status'] === 404 && $privatePdf['body'] === 'Flight plan unavailable.',
            'Private source gallery PDF must not be disclosed to anonymous visitors.');
    }
    galleryWorkflowOfpAssertPdf($admin, $unpublishedLinks['inline'], $ofpBytes);
    echo "PASS gallery workflow private OFP access control preserves real Admin access\n";

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

    $stage = 'real runtime republished and unlisted OFP';
    $republishedLinks = galleryWorkflowOfpLinks($anonymous, (string) $gallery['folder_path'], $id);
    galleryWorkflowOfpAssertPdf($anonymous, $republishedLinks['inline'], $ofpBytes);
    $pdo->prepare("UPDATE galleries SET access_listing = 'unlisted' WHERE id = ?")->execute([$id]);
    $unlistedLinks = galleryWorkflowOfpLinks($anonymous, (string) $gallery['folder_path'], $id);
    galleryWorkflowOfpAssertPdf($anonymous, $unlistedLinks['inline'], $ofpBytes);
    galleryWorkflowOfpAssertPdf($anonymous, $unlistedLinks['download'], $ofpBytes, true);
    $pdo->prepare("UPDATE galleries SET access_listing = 'listed' WHERE id = ?")->execute([$id]);
    echo "PASS gallery workflow original OFP republished/unlisted access\n";

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
