<?php
/**
 * Download and ZIP service model.
 *
 * This module owns generated ZIP archives, cache expiry checks, gallery archive
 * entry collection, and safe download streaming. It was separated from
 * app/services.php because archive generation is a self-contained runtime
 * concern with its own cache lifecycle.
 *
 * Function names and signatures are intentionally unchanged. Existing
 * controllers can continue calling build_gallery_zip(), build_all_zip(),
 * cleanup_expired_zip_cache(), and send_download() without behaviour changes.
 */

/**
 * Ensure the ZIP cache folder exists and return its normalized path.
 */
function zip_cache_dir(): string
{
    // Variable $path stores this steps working value.
    $path = (string) cms_config()['zip_cache_path'];
    if (!is_dir($path)) {
        mkdir($path, 0775, true);
    }
    return rtrim($path, DIRECTORY_SEPARATOR);
}

/**
 * Return the number of seconds a generated ZIP file may remain in cache.
 */
function zip_cache_ttl_seconds(): int
{
    return 7 * 24 * 60 * 60;
}

/**
 * Return true when a cached ZIP path is still inside the ZIP cache and fresh enough to reuse.
 */
function zip_cache_file_is_fresh(string $filePath, ?int $now = null): bool
{
    if ($now === null) {
        // $now stores an intermediate value used by the surrounding gallery workflow.
        $now = time();
    }
    if (!is_file($filePath) || !path_inside(zip_cache_dir(), $filePath)) {
        return false;
    }
    // Variable $modifiedAt stores this steps working value.
    $modifiedAt = filemtime($filePath);
    if ($modifiedAt === false) {
        return false;
    }
    return ($now - $modifiedAt) <= zip_cache_ttl_seconds();
}

/**
 * Remove expired generated ZIP files and database rows from the ZIP cache.
 */
function cleanup_expired_zip_cache(?int $now = null): array
{
    if ($now === null) {
        // $now stores an intermediate value used by the surrounding gallery workflow.
        $now = time();
    }
    // Variable $dir stores this steps working value.
    $dir = zip_cache_dir();
    // Variable $cutoff stores this steps working value.
    $cutoff = $now - zip_cache_ttl_seconds();
    // Variable $deletedFiles stores this steps working value.
    $deletedFiles = 0;
    // Variable $deletedRows stores this steps working value.
    $deletedRows = 0;

    // Variable $stmt stores this steps working value.
    $stmt = db()->query('SELECT id, file_path FROM zip_archives');
    foreach ($stmt->fetchAll() as $archive) {
        // Variable $filePath stores this steps working value.
        $filePath = (string) $archive['file_path'];
        // Variable $removeRow stores this steps working value.
        $removeRow = false;
        if (!path_inside($dir, $filePath)) {
            // $removeRow stores an intermediate value used by the surrounding gallery workflow.
            $removeRow = true;
        } elseif (!is_file($filePath)) {
            // $removeRow stores an intermediate value used by the surrounding gallery workflow.
            $removeRow = true;
        } else {
            // Variable $modifiedAt stores this steps working value.
            $modifiedAt = filemtime($filePath);
            if ($modifiedAt === false || $modifiedAt < $cutoff) {
                if (@unlink($filePath)) {
                    $deletedFiles++;
                }
                // $removeRow stores an intermediate value used by the surrounding gallery workflow.
                $removeRow = true;
            }
        }
        if ($removeRow) {
            // Variable $delete stores this steps working value.
            $delete = db()->prepare('DELETE FROM zip_archives WHERE id = ?');
            $delete->execute([(int) $archive['id']]);
            $deletedRows += $delete->rowCount();
        }
    }

    // Variable $iterator stores this steps working value.
    $iterator = new DirectoryIterator($dir);
    foreach ($iterator as $entry) {
        if (!$entry->isFile() || strtolower($entry->getExtension()) !== 'zip') {
            continue;
        }
        // Variable $path stores this steps working value.
        $path = $entry->getPathname();
        if ($entry->getMTime() < $cutoff && path_inside($dir, $path) && @unlink($path)) {
            $deletedFiles++;
        }
    }

    return ['files' => $deletedFiles, 'rows' => $deletedRows, 'ttl_seconds' => zip_cache_ttl_seconds()];
}

/**
 * Return descendant galleries for one gallery, including the gallery itself.
 */
function gallery_zip_gallery_rows(array $gallery, bool $publicOnly): array
{
    // Variable $folderPath stores this steps working value.
    $folderPath = normalize_relative_path((string) $gallery['folder_path']);
    if ($folderPath === '') {
        return [];
    }

    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare('SELECT * FROM galleries WHERE folder_path = ? OR folder_path LIKE ? ORDER BY CHAR_LENGTH(folder_path), folder_path, id');
    $stmt->execute([$folderPath, $folderPath . '/%']);

    // Variable $rows stores this steps working value.
    $rows = [];
    foreach ($stmt->fetchAll() as $candidate) {
        if ($publicOnly && !visitor_can_access_gallery($candidate)) {
            continue;
        }
        $rows[] = $candidate;
    }
    return $rows;
}

/**
 * Return gallery IDs from rows as integer values.
 */
function gallery_zip_gallery_ids(array $galleries): array
{
    // Variable $ids stores this steps working value.
    $ids = [];
    foreach ($galleries as $gallery) {
        $ids[] = (int) $gallery['id'];
    }
    return $ids;
}

/**
 * Build a content signature for the admin "all galleries" ZIP cache entry.
 */
function all_zip_signature(): string
{
    // Variable $rows stores this steps working value.
    $rows = db()->query("SELECT g.folder_path, g.updated_at AS gallery_updated_at, i.relative_path, i.file_size, i.modified_at, i.visibility
        FROM galleries g
        LEFT JOIN images i ON i.gallery_id = g.id
        ORDER BY g.folder_path, i.relative_path")->fetchAll();
    return hash('sha256', json_encode($rows, JSON_UNESCAPED_SLASHES));
}

/**
 * Create or reuse a ZIP archive for one gallery.
 */
function build_gallery_zip(int $galleryId, bool $publicOnly): string
{
    cleanup_expired_zip_cache();
    // Variable $gallery stores this steps working value.
    $gallery = find_gallery($galleryId);
    if (!$gallery) {
        throw new RuntimeException('Gallery not found.');
    }
    // Variable $signature stores this steps working value.
    $signature = gallery_zip_signature($galleryId, $publicOnly);
    // Variable $scope stores this steps working value.
    $scope = 'gallery';
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare('SELECT * FROM zip_archives WHERE scope = ? AND gallery_id = ? AND content_signature = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$scope, $galleryId, $signature]);
    // Variable $cached stores this steps working value.
    $cached = $stmt->fetch();
    if ($cached && zip_cache_file_is_fresh((string) $cached['file_path'])) {
        return (string) $cached['file_path'];
    }
    if ($cached) {
        // Variable $cachedPath stores this steps working value.
        $cachedPath = (string) $cached['file_path'];
        if (path_inside(zip_cache_dir(), $cachedPath) && is_file($cachedPath)) {
            @unlink($cachedPath);
        }
        // Variable $delete stores this steps working value.
        $delete = db()->prepare('DELETE FROM zip_archives WHERE id = ?');
        $delete->execute([(int) $cached['id']]);
    }

    // Variable $filePath stores this steps working value.
    $filePath = zip_cache_dir() . DIRECTORY_SEPARATOR . 'gallery-' . $galleryId . '-' . $signature . '.zip';
    create_zip($filePath, gallery_zip_entries($gallery, $publicOnly));
    // Variable $insert stores this steps working value.
    $insert = db()->prepare('INSERT INTO zip_archives (scope, gallery_id, file_path, content_signature, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)');
    $insert->execute([$scope, $galleryId, $filePath, $signature, now_sql(), now_sql()]);
    return $filePath;
}

/**
 * Create or reuse a ZIP archive containing every imported gallery.
 */
function build_all_zip(): string
{
    cleanup_expired_zip_cache();
    // Variable $signature stores this steps working value.
    $signature = all_zip_signature();
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare('SELECT * FROM zip_archives WHERE scope = ? AND gallery_id IS NULL AND content_signature = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute(['all', $signature]);
    // Variable $cached stores this steps working value.
    $cached = $stmt->fetch();
    if ($cached && zip_cache_file_is_fresh((string) $cached['file_path'])) {
        return (string) $cached['file_path'];
    }
    if ($cached) {
        // Variable $cachedPath stores this steps working value.
        $cachedPath = (string) $cached['file_path'];
        if (path_inside(zip_cache_dir(), $cachedPath) && is_file($cachedPath)) {
            @unlink($cachedPath);
        }
        // Variable $delete stores this steps working value.
        $delete = db()->prepare('DELETE FROM zip_archives WHERE id = ?');
        $delete->execute([(int) $cached['id']]);
    }

    // Variable $galleries stores this steps working value.
    $galleries = db()->query('SELECT * FROM galleries ORDER BY CHAR_LENGTH(folder_path), folder_path, id')->fetchAll();
    // Variable $entries stores this steps working value.
    $entries = gallery_zip_entries_from_galleries($galleries, false);
    // Variable $filePath stores this steps working value.
    $filePath = zip_cache_dir() . DIRECTORY_SEPARATOR . 'all-' . $signature . '.zip';
    create_zip($filePath, $entries);
    // Variable $insert stores this steps working value.
    $insert = db()->prepare('INSERT INTO zip_archives (scope, gallery_id, file_path, content_signature, created_at, updated_at) VALUES (?, NULL, ?, ?, ?, ?)');
    $insert->execute(['all', $filePath, $signature, now_sql(), now_sql()]);
    return $filePath;
}

/**
 * Produce the directories and files that should be stored in a gallery ZIP.
 */
function gallery_zip_entries(array $gallery, bool $publicOnly): array
{
    return gallery_zip_entries_from_galleries(gallery_zip_gallery_rows($gallery, $publicOnly), $publicOnly);
}

/**
 * Produce ZIP entries from already selected gallery rows.
 */
function gallery_zip_entries_from_galleries(array $galleries, bool $publicOnly): array
{
    // Variable $directories stores this steps working value.
    $directories = [];
    // Variable $files stores this steps working value.
    $files = [];
    // Variable $galleryById stores this steps working value.
    $galleryById = [];

    foreach ($galleries as $gallery) {
        // Variable $folderPath stores this steps working value.
        $folderPath = normalize_relative_path((string) $gallery['folder_path']);
        if ($folderPath === '') {
            continue;
        }
        $directories[$folderPath] = $folderPath;
        $galleryById[(int) $gallery['id']] = $gallery;
    }

    // Variable $galleryIds stores this steps working value.
    $galleryIds = gallery_zip_gallery_ids($galleries);
    if ($galleryIds) {
        // Variable $placeholders stores this steps working value.
        $placeholders = implode(',', array_fill(0, count($galleryIds), '?'));
        // Variable $imageVisibilitySql stores this steps working value.
        $imageVisibilitySql = $publicOnly ? " AND visibility = 'public'" : '';
        // Variable $stmt stores this steps working value.
        $stmt = db()->prepare("SELECT * FROM images WHERE gallery_id IN ($placeholders)" . $imageVisibilitySql . ' ORDER BY gallery_id, sort_order, filename, relative_path');
        $stmt->execute($galleryIds);
        foreach ($stmt->fetchAll() as $image) {
            // Variable $imageGallery stores this steps working value.
            $imageGallery = $galleryById[(int) $image['gallery_id']] ?? null;
            if (!$imageGallery) {
                continue;
            }
            // Variable $absolute stores this steps working value.
            $absolute = image_abs_path($image, $imageGallery);
            if (!is_file($absolute)) {
                continue;
            }
            // Variable $relativePath stores this steps working value.
            $relativePath = normalize_relative_path((string) $image['relative_path']);
            // Variable $zipPath stores this steps working value.
            $zipPath = normalize_relative_path((string) $imageGallery['folder_path'] . '/' . $relativePath);
            $files[$zipPath] = [
                'type' => 'file',
                'absolute' => $absolute,
                'zip_path' => $zipPath,
            ];

            // Variable $parentDirectory stores this steps working value.
            $parentDirectory = dirname(str_replace('\\', '/', $zipPath));
            while ($parentDirectory !== '.' && $parentDirectory !== '') {
                $directories[$parentDirectory] = $parentDirectory;
                // $parentDirectory stores an intermediate value used by the surrounding gallery workflow.
                $parentDirectory = dirname($parentDirectory);
            }
        }
    }

    // Variable $entries stores this steps working value.
    $entries = [];
    ksort($directories, SORT_NATURAL | SORT_FLAG_CASE);
    foreach ($directories as $directory) {
        $entries[] = [
            'type' => 'directory',
            'zip_path' => rtrim($directory, '/') . '/',
        ];
    }
    ksort($files, SORT_NATURAL | SORT_FLAG_CASE);
    foreach ($files as $file) {
        $entries[] = $file;
    }
    return $entries;
}

/**
 * Write a ZIP archive to disk.
 */
function create_zip(string $filePath, array $entries): void
{
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('ZipArchive is not available.');
    }
    // Variable $zip stores this steps working value.
    $zip = new ZipArchive();
    if ($zip->open($filePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to create ZIP archive.');
    }
    foreach ($entries as $entry) {
        // Variable $zipPath stores this steps working value.
        $zipPath = normalize_relative_path((string) ($entry['zip_path'] ?? ''));
        if ($zipPath === '') {
            continue;
        }
        if (($entry['type'] ?? 'file') === 'directory') {
            $zip->addEmptyDir(rtrim($zipPath, '/') . '/');
            continue;
        }
        // Variable $absolute stores this steps working value.
        $absolute = (string) ($entry['absolute'] ?? '');
        if ($absolute !== '' && is_file($absolute)) {
            $zip->addFile($absolute, $zipPath);
            $zip->setCompressionName($zipPath, ZipArchive::CM_STORE);
        }
    }
    $zip->close();
    if (!is_file($filePath)) {
        throw new RuntimeException('Unable to finalize ZIP archive.');
    }
}

/**
 * Stream a ZIP file to the browser and stop processing.
 */
function send_download(string $filePath, string $downloadName): never
{
    if (!is_file($filePath) || !path_inside(zip_cache_dir(), $filePath)) {
        http_response_code(404);
        exit('Download not found.');
    }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $downloadName) . '"');
    header('Content-Length: ' . filesize($filePath));
    readfile($filePath);
    exit;
}
