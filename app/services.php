<?php

declare(strict_types=1);

/**
 * Configured filesystem root where gallery folders are stored.
 */
function galleries_root(): string
{
    return rtrim((string) cms_config()['galleries_root'], DIRECTORY_SEPARATOR);
}

/**
 * Resolve a gallery's relative folder path to an absolute filesystem path.
 */
function gallery_abs_path(string $relativePath): string
{
    // Variable $relativePath stores this steps working value.
    $relativePath = normalize_relative_path($relativePath);
    // Variable $path stores this steps working value.
    $path = galleries_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    if (!path_inside(galleries_root(), $path)) {
        throw new RuntimeException('Gallery path is outside the configured root.');
    }
    return $path;
}

/**
 * Resolve an image record to its absolute file path inside its gallery folder.
 */
function image_abs_path(array $image, array $gallery): string
{
    // Variable $galleryRoot stores this steps working value.
    $galleryRoot = gallery_abs_path((string) $gallery['folder_path']);
    // Variable $path stores this steps working value.
    $path = $galleryRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, normalize_relative_path((string) $image['relative_path']));
    // Variable $parent stores this steps working value.
    $parent = dirname($path);
    if (!path_inside($galleryRoot, $parent)) {
        throw new RuntimeException('Image path is outside its gallery.');
    }
    return $path;
}

/**
 * Find folders under galleries_root that can become gallery records.
 *
 * A folder is a candidate when it contains direct images, descendant images, or
 * a gallery.json sidecar. Descendant images allow empty parent folders to become
 * top-level galleries that contain subgalleries.
 */
function discover_gallery_candidates(): array
{
    // Variable $root stores this steps working value.
    $root = galleries_root();
    if (!is_dir($root)) {
        return [];
    }

    // Variable $pdo stores this steps working value.
    $pdo = db();
    // Variable $known stores this steps working value.
    $known = $pdo->query('SELECT folder_path FROM galleries')->fetchAll(PDO::FETCH_COLUMN);
    // Variable $known stores this steps working value.
    $known = array_flip($known);
    // Variable $candidates stores this steps working value.
    $candidates = [];
    // Variable $ignoreNames stores this steps working value.
    $ignoreNames = ['cache', 'thumbs', 'thumbnail', 'thumbnails', 'preview', 'previews'];
    // Variable $iterator stores this steps working value.
    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            function (SplFileInfo $file) use ($ignoreNames): bool {
                if (!$file->isDir()) {
                    return true;
                }
                // Variable $name stores this steps working value.
                $name = $file->getFilename();
                return !str_starts_with($name, '.') && !in_array(strtolower($name), $ignoreNames, true);
            }
        ),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        if (!$item->isDir()) {
            continue;
        }
        // Variable $relative stores this steps working value.
        $relative = normalize_relative_path(substr($item->getPathname(), strlen($root)));
        if ($relative === '' || isset($known[$relative])) {
            continue;
        }
        // Variable $hasImages stores this steps working value.
        $hasImages = false;
        foreach (new DirectoryIterator($item->getPathname()) as $child) {
            if ($child->isFile() && is_supported_image_path($child->getFilename())) {
                // Variable $hasImages stores this steps working value.
                $hasImages = true;
                break;
            }
        }
        // Variable $hasDescendantImages stores this steps working value.
        $hasDescendantImages = false;
        if (!$hasImages) {
            // Variable $descendants stores this steps working value.
            $descendants = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($item->getPathname(), FilesystemIterator::SKIP_DOTS));
            foreach ($descendants as $descendant) {
                if ($descendant->isFile() && is_supported_image_path($descendant->getFilename())) {
                    // Variable $hasDescendantImages stores this steps working value.
                    $hasDescendantImages = true;
                    break;
                }
            }
        }
        // Variable $jsonPath stores this steps working value.
        $jsonPath = $item->getPathname() . DIRECTORY_SEPARATOR . 'gallery.json';
        if ($hasImages || $hasDescendantImages || is_file($jsonPath)) {
            // Variable $metadata stores this steps working value.
            $metadata = read_gallery_sidecar($jsonPath);
            $candidates[] = [
                'folder_path' => $relative,
                'title' => $metadata['title'] ?? basename($relative),
                'description' => $metadata['description'] ?? '',
                'visibility' => $metadata['visibility'] ?? 'draft',
                'sort_order' => (int) ($metadata['sort_order'] ?? 0),
            ];
        }
    }

    return $candidates;
}

/**
 * Read optional gallery metadata from gallery.json.
 */
function read_gallery_sidecar(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    // Variable $data stores this steps working value.
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

/**
 * Persist editable gallery metadata back into gallery.json.
 */
function write_gallery_sidecar(array $gallery): void
{
    // Variable $path stores this steps working value.
    $path = gallery_abs_path((string) $gallery['folder_path']) . DIRECTORY_SEPARATOR . 'gallery.json';
    // Variable $data stores this steps working value.
    $data = [
        'title' => $gallery['title'],
        'description' => $gallery['description'],
        'visibility' => $gallery['visibility'],
        'sort_order' => (int) $gallery['sort_order'],
    ];
    if (!empty($gallery['cover_image_id'])) {
        // Variable $cover stores this steps working value.
        $cover = find_image((int) $gallery['cover_image_id']);
        if ($cover) {
            $data['cover'] = $cover['relative_path'];
        }
    }
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/**
 * Create gallery rows for selected discovered folders.
 */
function import_galleries(array $folderPaths, bool $createThumbnails = false): array
{
    // Variable $pdo stores this steps working value.
    $pdo = db();
    // Variable $candidates stores this steps working value.
    $candidates = [];
    foreach (discover_gallery_candidates() as $candidate) {
        $candidates[$candidate['folder_path']] = $candidate;
    }
    // Variable $requested stores this steps working value.
    $requested = array_map(static fn ($path): string => normalize_relative_path((string) $path), $folderPaths);
    // Variable $folderPaths stores this steps working value.
    $folderPaths = [];
    foreach ($requested as $requestedPath) {
        foreach (array_keys($candidates) as $candidatePath) {
            if ($candidatePath === $requestedPath || str_starts_with($candidatePath, $requestedPath . '/')) {
                $folderPaths[$candidatePath] = $candidatePath;
            }
        }
    }
    usort($folderPaths, static fn ($a, $b): int => substr_count((string) $a, '/') <=> substr_count((string) $b, '/'));
    // Variable $imported stores this steps working value.
    $imported = 0;
    // Variable $scanned stores this steps working value.
    $scanned = 0;
    // Variable $thumbs stores this steps working value.
    $thumbs = 0;
    // Variable $importedIds stores this steps working value.
    $importedIds = [];
    foreach ($folderPaths as $folderPath) {
        if (!isset($candidates[$folderPath])) {
            continue;
        }
        // Variable $candidate stores this steps working value.
        $candidate = $candidates[$folderPath];
        // Variable $visibility stores this steps working value.
        $visibility = in_array($candidate['visibility'], ['draft', 'public', 'private'], true) ? $candidate['visibility'] : 'draft';
        // Variable $parent stores this steps working value.
        $parent = find_parent_gallery_for_path($folderPath);
        // Variable $stmt stores this steps working value.
        $stmt = $pdo->prepare('INSERT INTO galleries (parent_id, folder_path, folder_path_hash, slug, title, description, sort_order, visibility, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $parent ? (int) $parent['id'] : null,
            $folderPath,
            hash('sha256', $folderPath),
            unique_slug($pdo, (string) $candidate['title']),
            $candidate['title'],
            $candidate['description'],
            (int) $candidate['sort_order'],
            $visibility,
            now_sql(),
            now_sql(),
        ]);
        // Variable $gallery stores this steps working value.
        $gallery = find_gallery((int) $pdo->lastInsertId());
        if ($gallery) {
            write_gallery_sidecar($gallery);
            $importedIds[] = (int) $gallery['id'];
        }
        $imported++;
    }
    sync_gallery_parent_ids();
    foreach ($importedIds as $galleryId) {
        $scanned += scan_gallery_images($galleryId);
        if ($createThumbnails) {
            $thumbs += create_gallery_thumbnails($galleryId);
        }
    }
    return ['imported' => $imported, 'scanned' => $scanned, 'thumbnails' => $thumbs];
}

/**
 * Import/update image rows for images directly inside one gallery folder.
 *
 * Child-folder images are intentionally ignored here because child folders are
 * represented as subgalleries with their own scans.
 */
function scan_gallery_images(int $galleryId): int
{
    // Variable $gallery stores this steps working value.
    $gallery = find_gallery($galleryId);
    if (!$gallery) {
        return 0;
    }
    // Variable $root stores this steps working value.
    $root = gallery_abs_path((string) $gallery['folder_path']);
    if (!is_dir($root)) {
        return 0;
    }

    // Variable $pdo stores this steps working value.
    $pdo = db();
    // Variable $count stores this steps working value.
    $count = 0;
    foreach (new DirectoryIterator($root) as $file) {
        if (!$file->isFile() || !is_supported_image_path($file->getFilename())) {
            continue;
        }
        // Variable $relative stores this steps working value.
        $relative = normalize_relative_path(substr($file->getPathname(), strlen($root)));
        // Variable $info stores this steps working value.
        $info = @getimagesize($file->getPathname());
        if ($info === false || empty($info['mime']) || !str_starts_with((string) $info['mime'], 'image/')) {
            continue;
        }
        // Variable $modifiedAt stores this steps working value.
        $modifiedAt = date('Y-m-d H:i:s', $file->getMTime());
        // Variable $existing stores this steps working value.
        $existing = find_image_by_path($galleryId, $relative);
        if (!$existing) {
            // Variable $stmt stores this steps working value.
            $stmt = $pdo->prepare('INSERT INTO images (gallery_id, relative_path, relative_path_hash, filename, title, width, height, mime_type, file_size, modified_at, checksum_sha256, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $galleryId,
                $relative,
                hash('sha256', $relative),
                $file->getFilename(),
                pathinfo($file->getFilename(), PATHINFO_FILENAME),
                (int) $info[0],
                (int) $info[1],
                (string) $info['mime'],
                $file->getSize(),
                $modifiedAt,
                hash_file('sha256', $file->getPathname()) ?: null,
                now_sql(),
                now_sql(),
            ]);
            $count++;
            continue;
        }
        if ((int) $existing['file_size'] !== $file->getSize() || (string) $existing['modified_at'] !== $modifiedAt) {
            // Variable $stmt stores this steps working value.
            $stmt = $pdo->prepare('UPDATE images SET filename = ?, width = ?, height = ?, mime_type = ?, file_size = ?, modified_at = ?, checksum_sha256 = ?, updated_at = ? WHERE id = ?');
            $stmt->execute([
                $file->getFilename(),
                (int) $info[0],
                (int) $info[1],
                (string) $info['mime'],
                $file->getSize(),
                $modifiedAt,
                hash_file('sha256', $file->getPathname()) ?: null,
                now_sql(),
                (int) $existing['id'],
            ]);
            $count++;
        }
    }
    apply_gallery_cover_from_sidecar($gallery);
    ensure_gallery_cover((int) $gallery['id']);
    return $count;
}

/**
 * Thumbnail variants generated for web views.
 */
function thumbnail_sizes(): array
{
    return [300, 800];
}

/**
 * Resolve the thumbs folder for a gallery and create it when requested.
 */
function gallery_thumbs_dir(array $gallery, bool $create = false): string
{
    // Variable $path stores this steps working value.
    $path = gallery_abs_path((string) $gallery['folder_path']) . DIRECTORY_SEPARATOR . 'thumbs';
    if ($create && !is_dir($path)) {
        mkdir($path, 0775, true);
    }
    if (!path_inside(gallery_abs_path((string) $gallery['folder_path']), $path)) {
        throw new RuntimeException('Thumbnail path is outside its gallery.');
    }
    return $path;
}

/**
 * Build the generated JPEG thumbnail filename for an image and size.
 */
function thumbnail_filename(array $image, int $size): string
{
    return pathinfo((string) $image['filename'], PATHINFO_FILENAME) . '_thumb' . $size . '.jpg';
}

/**
 * Resolve one generated thumbnail path.
 */
function thumbnail_abs_path(array $image, array $gallery, int $size): string
{
    if (!in_array($size, thumbnail_sizes(), true)) {
        throw new RuntimeException('Unsupported thumbnail size.');
    }
    return gallery_thumbs_dir($gallery, false) . DIRECTORY_SEPARATOR . thumbnail_filename($image, $size);
}

/**
 * Return the best public URL for an image thumbnail, falling back to the source.
 */
function thumbnail_url(array $image, int $size): string
{
    // Variable $gallery stores this steps working value.
    $gallery = find_gallery((int) $image['gallery_id']);
    if ($gallery) {
        try {
            if (is_file(thumbnail_abs_path($image, $gallery, $size))) {
                return url_for('thumb', ['id' => $image['id'], 'size' => $size]);
            }
        } catch (RuntimeException) {
            return url_for('media', ['id' => $image['id']]);
        }
    }
    return url_for('media', ['id' => $image['id']]);
}

/**
 * Generate all configured thumbnails for direct images in one gallery.
 */
function create_gallery_thumbnails(int $galleryId): int
{
    // Variable $gallery stores this steps working value.
    $gallery = find_gallery($galleryId);
    if (!$gallery) {
        return 0;
    }
    // Variable $count stores this steps working value.
    $count = 0;
    foreach (gallery_images($galleryId, false) as $image) {
        $count += create_image_thumbnails($image, $gallery);
    }
    return $count;
}

/**
 * Generate all configured thumbnails for every imported image.
 */
function create_all_thumbnails(): int
{
    // Variable $count stores this steps working value.
    $count = 0;
    foreach (db()->query('SELECT id FROM galleries ORDER BY folder_path')->fetchAll(PDO::FETCH_COLUMN) as $galleryId) {
        $count += create_gallery_thumbnails((int) $galleryId);
    }
    return $count;
}

/**
 * Rebuild web-optimized JPEG thumbnails for one source image.
 */
function create_image_thumbnails(array $image, array $gallery): int
{
    return create_image_thumbnails_result($image, $gallery)['created'];
}

/**
 * Rebuild missing or stale thumbnails and report created/skipped variants.
 */
function create_image_thumbnails_result(array $image, array $gallery): array
{
    // Variable $sourcePath stores this steps working value.
    $sourcePath = image_abs_path($image, $gallery);
    if (!is_file($sourcePath)) {
        return ['created' => 0, 'skipped' => 0];
    }
    gallery_thumbs_dir($gallery, true);
    // Variable $targets stores this steps working value.
    $targets = [];
    // Variable $skipped stores this steps working value.
    $skipped = 0;
    foreach (thumbnail_sizes() as $size) {
        // Variable $targetPath stores this steps working value.
        $targetPath = thumbnail_abs_path($image, $gallery, $size);
        if (is_file($targetPath) && filemtime($targetPath) >= filemtime($sourcePath)) {
            $skipped++;
            continue;
        }
        $targets[$size] = $targetPath;
    }
    if (!$targets) {
        return ['created' => 0, 'skipped' => $skipped];
    }
    if (!extension_loaded('gd')) {
        return ['created' => 0, 'skipped' => $skipped];
    }
    // Variable $info stores this steps working value.
    $info = @getimagesize($sourcePath);
    if ($info === false || empty($info['mime'])) {
        return ['created' => 0, 'skipped' => $skipped];
    }
    // Variable $source stores this steps working value.
    $source = image_create_from_path($sourcePath, (string) $info['mime']);
    if (!$source) {
        return ['created' => 0, 'skipped' => $skipped];
    }
    // Variable $created stores this steps working value.
    $created = 0;
    foreach ($targets as $size => $targetPath) {
        if (write_resized_jpeg($source, (int) $info[0], (int) $info[1], $size, $targetPath)) {
            $created++;
        }
    }
    imagedestroy($source);
    return ['created' => $created, 'skipped' => $skipped];
}

/**
 * Return image IDs directly owned by the selected galleries.
 */
function image_ids_for_galleries(array $galleryIds): array
{
    // Variable $galleryIds stores this steps working value.
    $galleryIds = array_values(array_unique(array_filter(array_map('intval', $galleryIds), static fn (int $id): bool => $id > 0)));
    if (!$galleryIds) {
        return [];
    }
    // Variable $placeholders stores this steps working value.
    $placeholders = implode(',', array_fill(0, count($galleryIds), '?'));
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare("SELECT id FROM images WHERE gallery_id IN ($placeholders) AND relative_path NOT LIKE '%/%' ORDER BY gallery_id, sort_order, filename");
    $stmt->execute($galleryIds);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Return every imported direct image ID in stable dashboard order.
 */
function all_image_ids(): array
{
    // Variable $rows stores this steps working value.
    $rows = db()->query("SELECT i.id FROM images i JOIN galleries g ON g.id = i.gallery_id WHERE i.relative_path NOT LIKE '%/%' ORDER BY g.folder_path, i.sort_order, i.filename")->fetchAll(PDO::FETCH_COLUMN);
    return array_map('intval', $rows);
}

/**
 * Load a GD image resource from the supported source MIME types.
 */
function image_create_from_path(string $path, string $mime): GdImage|false
{
    return match ($mime) {
        'image/jpeg' => imagecreatefromjpeg($path),
        'image/png' => imagecreatefrompng($path),
        'image/gif' => imagecreatefromgif($path),
        'image/webp' => function_exists('imagecreatefromwebp') ? imagecreatefromwebp($path) : false,
        default => false,
    };
}

/**
 * Resize an image to a maximum longer side and write a progressive JPEG.
 */
function write_resized_jpeg(GdImage $source, int $width, int $height, int $maxSide, string $targetPath): bool
{
    // Variable $scale stores this steps working value.
    $scale = min(1.0, $maxSide / max($width, $height));
    // Variable $targetWidth stores this steps working value.
    $targetWidth = max(1, (int) round($width * $scale));
    // Variable $targetHeight stores this steps working value.
    $targetHeight = max(1, (int) round($height * $scale));
    // Variable $target stores this steps working value.
    $target = imagecreatetruecolor($targetWidth, $targetHeight);
    // Variable $white stores this steps working value.
    $white = imagecolorallocate($target, 255, 255, 255);
    imagefilledrectangle($target, 0, 0, $targetWidth, $targetHeight, $white);
    imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
    imageinterlace($target, true);
    // Variable $written stores this steps working value.
    $written = imagejpeg($target, $targetPath, 82);
    imagedestroy($target);
    return $written;
}

/**
 * Pick the first direct image as cover when the gallery has no explicit cover.
 */
function ensure_gallery_cover(int $galleryId): void
{
    // Variable $gallery stores this steps working value.
    $gallery = find_gallery($galleryId);
    if (!$gallery || !empty($gallery['cover_image_id'])) {
        return;
    }
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare("SELECT id FROM images WHERE gallery_id = ? AND relative_path NOT LIKE '%/%' ORDER BY CASE WHEN visibility = 'public' THEN 0 ELSE 1 END, sort_order, filename LIMIT 1");
    $stmt->execute([$galleryId]);
    // Variable $coverId stores this steps working value.
    $coverId = $stmt->fetchColumn();
    if (!$coverId) {
        return;
    }
    // Variable $update stores this steps working value.
    $update = db()->prepare('UPDATE galleries SET cover_image_id = ?, updated_at = ? WHERE id = ?');
    $update->execute([(int) $coverId, now_sql(), $galleryId]);
}

/**
 * Rebuild parent_id links from filesystem folder nesting.
 */
function sync_gallery_parent_ids(): void
{
    // Variable $galleries stores this steps working value.
    $galleries = db()->query('SELECT id, folder_path FROM galleries ORDER BY folder_path')->fetchAll();
    foreach ($galleries as $gallery) {
        // Variable $parent stores this steps working value.
        $parent = find_parent_gallery_for_path((string) $gallery['folder_path']);
        // Variable $parentId stores this steps working value.
        $parentId = $parent ? (int) $parent['id'] : null;
        if ($parentId === null) {
            // Variable $stmt stores this steps working value.
            $stmt = db()->prepare('UPDATE galleries SET parent_id = NULL, updated_at = ? WHERE id = ? AND parent_id IS NOT NULL');
            $stmt->execute([now_sql(), (int) $gallery['id']]);
            continue;
        }
        // Variable $stmt stores this steps working value.
        $stmt = db()->prepare('UPDATE galleries SET parent_id = ?, updated_at = ? WHERE id = ? AND (parent_id IS NULL OR parent_id <> ?)');
        $stmt->execute([$parentId, now_sql(), (int) $gallery['id'], $parentId]);
    }
}

/**
 * Return one gallery ID plus all descendant gallery IDs.
 */
function gallery_subtree_ids(int $galleryId): array
{
    // Variable $gallery stores this steps working value.
    $gallery = find_gallery($galleryId);
    if (!$gallery) {
        return [];
    }
    // Variable $folderPath stores this steps working value.
    $folderPath = normalize_relative_path((string) $gallery['folder_path']);
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare('SELECT id FROM galleries WHERE folder_path = ? OR folder_path LIKE ? ORDER BY folder_path');
    $stmt->execute([$folderPath, $folderPath . '/%']);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Return whether the current database has the picture-game migration applied.
 */
function picture_game_schema_ready(): bool
{
    try {
        $stmt = db()->query("SHOW COLUMNS FROM galleries LIKE 'picture_game_enabled'");
        if (!$stmt || !$stmt->fetch()) {
            return false;
        }
        $stmt = db()->query("SHOW TABLES LIKE 'picture_game_votes'");
        return $stmt && (bool) $stmt->fetch();
    } catch (PDOException) {
        return false;
    }
}

/**
 * Return whether the admin features added after the initial schema are ready.
 */
function admin_feature_schema_ready(): bool
{
    return picture_game_schema_ready() && admin_log_schema_ready();
}

/**
 * Return whether the admin log table exists.
 */
function admin_log_schema_ready(): bool
{
    try {
        $stmt = db()->query("SHOW COLUMNS FROM admin_logs LIKE 'status'");
        return $stmt && (bool) $stmt->fetch();
    } catch (PDOException) {
        return false;
    }
}

/**
 * Ensure the admin log table has the workflow columns used by the log UI.
 */
function ensure_admin_log_status_schema(): bool
{
    try {
        $tableExists = db()->query("SHOW TABLES LIKE 'admin_logs'");
        if (!$tableExists || !$tableExists->fetch()) {
            return false;
        }
        $statusColumn = db()->query("SHOW COLUMNS FROM admin_logs LIKE 'status'");
        if (!$statusColumn || !$statusColumn->fetch()) {
            db()->exec("ALTER TABLE admin_logs ADD COLUMN status ENUM('todo','doing','done','waiting') NOT NULL DEFAULT 'todo' AFTER level");
        }
        $statusUpdatedAtColumn = db()->query("SHOW COLUMNS FROM admin_logs LIKE 'status_updated_at'");
        if (!$statusUpdatedAtColumn || !$statusUpdatedAtColumn->fetch()) {
            db()->exec("ALTER TABLE admin_logs ADD COLUMN status_updated_at DATETIME NULL AFTER status");
        }
        $statusIndex = db()->query("SHOW INDEX FROM admin_logs WHERE Key_name = 'admin_logs_status_created_index'");
        if (!$statusIndex || !$statusIndex->fetch()) {
            db()->exec("ALTER TABLE admin_logs ADD KEY admin_logs_status_created_index (status, created_at)");
        }
        return true;
    } catch (PDOException) {
        return false;
    }
}

/**
 * Store one admin-visible log entry for operational failures or notices.
 */
function admin_log_event(string $level, string $eventKey, string $message, array $context = []): void
{
    if (!admin_log_schema_ready()) {
        return;
    }
    $allowedLevels = ['info', 'warning', 'error'];
    $level = in_array($level, $allowedLevels, true) ? $level : 'error';
    try {
        $user = current_user();
        $stmt = db()->prepare('INSERT INTO admin_logs (user_id, level, event_key, message, context_json, created_at) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $user ? (int) $user['id'] : null,
            $level,
            $eventKey,
            $message,
            $context ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            now_sql(),
        ]);
    } catch (Throwable) {
    }
}

/**
 * Allowed workflow states for admin log entries.
 */
function admin_log_status_options(): array
{
    return [
        'todo' => 'To be done',
        'doing' => 'Will be done',
        'waiting' => 'Waiting',
        'done' => 'Done',
    ];
}

/**
 * Human label for one admin log status.
 */
function admin_log_status_label(string $status): string
{
    $statuses = admin_log_status_options();
    return $statuses[$status] ?? $status;
}

/**
 * Return recent admin log entries for the dashboard.
 */
function admin_log_recent(int $limit = 12): array
{
    if (!admin_log_schema_ready()) {
        return [];
    }
    $stmt = db()->prepare('SELECT l.*, u.username FROM admin_logs l LEFT JOIN users u ON u.id = l.user_id ORDER BY l.created_at DESC, l.id DESC LIMIT ' . max(1, min(50, $limit)));
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Return admin log entries with optional status filtering.
 */
function admin_log_list(?string $status = null, int $limit = 100): array
{
    if (!admin_log_schema_ready()) {
        return [];
    }
    $sql = 'SELECT l.*, u.username FROM admin_logs l LEFT JOIN users u ON u.id = l.user_id';
    $params = [];
    $statuses = admin_log_status_options();
    if ($status !== null && isset($statuses[$status])) {
        $sql .= ' WHERE l.status = ?';
        $params[] = $status;
    }
    $sql .= ' ORDER BY l.status <> "done", l.status, l.created_at DESC, l.id DESC LIMIT ' . max(1, min(200, $limit));
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Update the workflow status for one admin log entry.
 */
function admin_log_update_status(int $logId, string $status): void
{
    $statuses = admin_log_status_options();
    if (!isset($statuses[$status])) {
        throw new RuntimeException('Invalid log status.');
    }
    if (!admin_log_schema_ready() && !ensure_admin_log_status_schema()) {
        throw new RuntimeException('Admin log schema is not ready.');
    }
    $stmt = db()->prepare('UPDATE admin_logs SET status = ?, status_updated_at = ? WHERE id = ?');
    $stmt->execute([$status, now_sql(), $logId]);
    $check = db()->prepare('SELECT status FROM admin_logs WHERE id = ?');
    $check->execute([$logId]);
    if ($check->fetchColumn() !== $status) {
        throw new RuntimeException('Admin log entry was not updated.');
    }
}

/**
 * Return direct child galleries for a parent gallery.
 */
function child_galleries(int $parentId, bool $publicOnly): array
{
    // Variable $sql stores this steps working value.
    $sql = "SELECT g.*, COUNT(i.id) AS image_count
        FROM galleries g
        LEFT JOIN images i ON i.gallery_id = g.id AND i.visibility = 'public' AND i.relative_path NOT LIKE '%/%'
        WHERE g.parent_id = ?";
    // Variable $params stores this steps working value.
    $params = [$parentId];
    if ($publicOnly) {
        $sql .= " AND g.visibility = 'public'";
    }
    $sql .= ' GROUP BY g.id ORDER BY g.sort_order, g.title';
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Walk from a gallery to its root ancestors for breadcrumb rendering.
 */
function gallery_ancestors(array $gallery, bool $publicOnly): array
{
    // Variable $ancestors stores this steps working value.
    $ancestors = [];
    // Variable $parentId stores this steps working value.
    $parentId = $gallery['parent_id'] ?? null;
    while ($parentId) {
        // Variable $parent stores this steps working value.
        $parent = find_gallery((int) $parentId);
        if (!$parent || ($publicOnly && $parent['visibility'] !== 'public')) {
            break;
        }
        array_unshift($ancestors, $parent);
        // Variable $parentId stores this steps working value.
        $parentId = $parent['parent_id'] ?? null;
    }
    return $ancestors;
}

/**
 * Public wrapper for the preferred direct cover image.
 */
function gallery_cover_image(int $galleryId, bool $publicOnly): ?array
{
    return gallery_direct_cover_image($galleryId, $publicOnly);
}

/**
 * Return the explicit cover image or first direct image for one gallery.
 */
function gallery_direct_cover_image(int $galleryId, bool $publicOnly): ?array
{
    // Variable $gallery stores this steps working value.
    $gallery = find_gallery($galleryId);
    if (!$gallery) {
        return null;
    }
    if (!empty($gallery['cover_image_id'])) {
        // Variable $cover stores this steps working value.
        $cover = find_image((int) $gallery['cover_image_id']);
        if ($cover && !str_contains((string) $cover['relative_path'], '/') && (!$publicOnly || $cover['visibility'] === 'public')) {
            return $cover;
        }
    }
    // Variable $sql stores this steps working value.
    $sql = "SELECT * FROM images WHERE gallery_id = ? AND relative_path NOT LIKE '%/%'";
    if ($publicOnly) {
        $sql .= " AND visibility = 'public'";
    }
    $sql .= " ORDER BY CASE WHEN visibility = 'public' THEN 0 ELSE 1 END, sort_order, filename LIMIT 1";
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare($sql);
    $stmt->execute([$galleryId]);
    // Variable $image stores this steps working value.
    $image = $stmt->fetch();
    return $image ?: null;
}

/**
 * Function `gallery_cover_collage_images` handles this scoped operation.
 */
function gallery_cover_collage_images(int $galleryId, bool $publicOnly, int $limit = 4): array
{
    // Variable $images stores this steps working value.
    $images = [];
    // Parent galleries without direct images borrow covers from child galleries.
    foreach (child_galleries($galleryId, $publicOnly) as $child) {
        // Variable $cover stores this steps working value.
        $cover = gallery_direct_cover_image((int) $child['id'], $publicOnly);
        if ($cover) {
            $images[(int) $cover['id']] = $cover;
        }
        if (count($images) >= $limit) {
            break;
        }
        foreach (gallery_cover_collage_images((int) $child['id'], $publicOnly, $limit - count($images)) as $descendantCover) {
            $images[(int) $descendantCover['id']] = $descendantCover;
            if (count($images) >= $limit) {
                break 2;
            }
        }
    }
    return array_values($images);
}

/**
 * Return gallery IDs in one gallery subtree that are enabled for picture game.
 *
 * Enabling a parent gallery makes its public descendants available for that
 * gallery's game, so meta-galleries can opt in their whole visible branch.
 */
function picture_game_gallery_ids(array $gallery): array
{
    // Variable $folderPath stores this steps working value.
    $folderPath = normalize_relative_path((string) $gallery['folder_path']);
    try {
        // Variable $stmt stores this steps working value.
        $stmt = db()->prepare("SELECT id, folder_path, picture_game_enabled FROM galleries WHERE visibility = 'public' AND (folder_path = ? OR folder_path LIKE ?) ORDER BY folder_path");
        $stmt->execute([$folderPath, $folderPath . '/%']);
    } catch (PDOException) {
        return [];
    }
    // Variable $enabledPaths stores this steps working value.
    $enabledPaths = [];
    // Variable $ids stores this steps working value.
    $ids = [];
    foreach ($stmt->fetchAll() as $candidate) {
        // Variable $candidatePath stores this steps working value.
        $candidatePath = normalize_relative_path((string) $candidate['folder_path']);
        if ((int) ($candidate['picture_game_enabled'] ?? 0) === 1) {
            $enabledPaths[] = $candidatePath;
        }
        foreach ($enabledPaths as $enabledPath) {
            if ($candidatePath === $enabledPath || str_starts_with($candidatePath, $enabledPath . '/')) {
                $ids[] = (int) $candidate['id'];
                break;
            }
        }
    }
    return array_values(array_unique($ids));
}

/**
 * Return public direct images that may participate in one gallery's game.
 */
function picture_game_images(array $gallery): array
{
    // Variable $galleryIds stores this steps working value.
    $galleryIds = picture_game_gallery_ids($gallery);
    if (!$galleryIds) {
        return [];
    }
    // Variable $placeholders stores this steps working value.
    $placeholders = implode(',', array_fill(0, count($galleryIds), '?'));
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare("SELECT i.*, g.title AS gallery_title FROM images i JOIN galleries g ON g.id = i.gallery_id WHERE i.gallery_id IN ($placeholders) AND i.visibility = 'public' AND i.relative_path NOT LIKE '%/%' ORDER BY g.folder_path, i.sort_order, i.filename");
    $stmt->execute($galleryIds);
    return $stmt->fetchAll();
}

/**
 * Return whether one gallery has enough opted-in public images for a game.
 */
function picture_game_available(array $gallery): bool
{
    return count(picture_game_images($gallery)) >= 2;
}

/**
 * Stable voter key for picture-game pair history.
 */
function picture_game_voter_hash(): string
{
    // Variable $user stores this steps working value.
    $user = current_user();
    if ($user) {
        return hash('sha256', 'user|' . (int) $user['id'] . '|' . (string) cms_config()['visitor_vote_secret']);
    }
    return visitor_hash();
}

/**
 * Normalize a pair of image IDs so A/B order cannot create duplicate pairs.
 */
function picture_game_pair_key(int $firstImageId, int $secondImageId): array
{
    return [min($firstImageId, $secondImageId), max($firstImageId, $secondImageId)];
}

/**
 * Return the next unplayed image pair for this voter in one gallery context.
 */
function next_picture_game_pair(array $gallery): ?array
{
    // Variable $images stores this steps working value.
    $images = picture_game_images($gallery);
    if (count($images) < 2) {
        return null;
    }
    // Variable $imageById stores this steps working value.
    $imageById = [];
    foreach ($images as $image) {
        $imageById[(int) $image['id']] = $image;
    }
    // Variable $ids stores this steps working value.
    $ids = array_keys($imageById);
    // Variable $voterHash stores this steps working value.
    $voterHash = picture_game_voter_hash();
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare('SELECT image_a_id, image_b_id FROM picture_game_votes WHERE gallery_id = ? AND voter_hash = ?');
    $stmt->execute([(int) $gallery['id'], $voterHash]);
    // Variable $seen stores this steps working value.
    $seen = [];
    foreach ($stmt->fetchAll() as $row) {
        $seen[(int) $row['image_a_id'] . ':' . (int) $row['image_b_id']] = true;
    }
    // Variable $pairs stores this steps working value.
    $pairs = [];
    for ($i = 0, $count = count($ids); $i < $count; $i++) {
        for ($j = $i + 1; $j < $count; $j++) {
            [$imageAId, $imageBId] = picture_game_pair_key((int) $ids[$i], (int) $ids[$j]);
            $key = $imageAId . ':' . $imageBId;
            if (!isset($seen[$key])) {
                $pairs[] = [$imageAId, $imageBId];
            }
        }
    }
    if (!$pairs) {
        return null;
    }
    // Variable $pair stores this steps working value.
    $pair = $pairs[random_int(0, count($pairs) - 1)];
    // Record display immediately so a voter does not keep seeing the same pair.
    db()->prepare('INSERT IGNORE INTO picture_game_votes (gallery_id, image_a_id, image_b_id, winner_image_id, voter_hash, created_at) VALUES (?, ?, ?, NULL, ?, ?)')->execute([
        (int) $gallery['id'],
        (int) $pair[0],
        (int) $pair[1],
        $voterHash,
        now_sql(),
    ]);
    return [
        'left' => $imageById[$pair[0]],
        'right' => $imageById[$pair[1]],
        'remaining_pairs' => count($pairs),
        'total_pairs' => (int) (count($ids) * (count($ids) - 1) / 2),
    ];
}

/**
 * Record one picture-game selection and upvote only the chosen image.
 */
function record_picture_game_vote(array $gallery, int $leftImageId, int $rightImageId, int $winnerImageId): void
{
    [$imageAId, $imageBId] = picture_game_pair_key($leftImageId, $rightImageId);
    if (!in_array($winnerImageId, [$imageAId, $imageBId], true)) {
        throw new RuntimeException('Selected image is not part of this pair.');
    }
    // Variable $allowedIds stores this steps working value.
    $allowedIds = array_map(static fn (array $image): int => (int) $image['id'], picture_game_images($gallery));
    if (!in_array($imageAId, $allowedIds, true) || !in_array($imageBId, $allowedIds, true)) {
        throw new RuntimeException('Image pair is not available in this game.');
    }
    // Variable $voterHash stores this steps working value.
    $voterHash = picture_game_voter_hash();
    // Variable $existing stores this steps working value.
    $existing = db()->prepare('SELECT winner_image_id FROM picture_game_votes WHERE gallery_id = ? AND voter_hash = ? AND image_a_id = ? AND image_b_id = ?');
    $existing->execute([(int) $gallery['id'], $voterHash, $imageAId, $imageBId]);
    // Variable $winner stores this steps working value.
    $winner = $existing->fetchColumn();
    if ($winner !== false && $winner !== null) {
        return;
    }
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare('INSERT INTO picture_game_votes (gallery_id, image_a_id, image_b_id, winner_image_id, voter_hash, created_at) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE winner_image_id = VALUES(winner_image_id)');
    $stmt->execute([(int) $gallery['id'], $imageAId, $imageBId, $winnerImageId, $voterHash, now_sql()]);
    // Variable $user stores this steps working value.
    $user = current_user();
    if ($user) {
        $vote = db()->prepare('INSERT INTO image_votes (image_id, user_id, visitor_hash, vote, created_at, updated_at) VALUES (?, ?, NULL, 1, ?, ?) ON DUPLICATE KEY UPDATE vote = VALUES(vote), updated_at = VALUES(updated_at)');
        $vote->execute([$winnerImageId, (int) $user['id'], now_sql(), now_sql()]);
        return;
    }
    $vote = db()->prepare('INSERT INTO image_votes (image_id, user_id, visitor_hash, vote, created_at, updated_at) VALUES (?, NULL, ?, 1, ?, ?) ON DUPLICATE KEY UPDATE vote = VALUES(vote), updated_at = VALUES(updated_at)');
    $vote->execute([$winnerImageId, visitor_hash(), now_sql(), now_sql()]);
}

/**
 * Return top global picture-game winners for one gallery context.
 */
function picture_game_top_images(array $gallery, int $limit = 3): array
{
    // Variable $images stores this steps working value.
    $images = picture_game_images($gallery);
    if (!$images) {
        return [];
    }
    // Variable $ids stores this steps working value.
    $ids = array_map(static fn (array $image): int => (int) $image['id'], $images);
    // Variable $placeholders stores this steps working value.
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare("SELECT i.*, g.title AS gallery_title,
            (SELECT COUNT(*) FROM picture_game_votes pgv WHERE pgv.winner_image_id = i.id) AS game_wins,
            (SELECT COALESCE(SUM(iv.vote), 0) FROM image_votes iv WHERE iv.image_id = i.id) AS score
        FROM images i
        JOIN galleries g ON g.id = i.gallery_id
        WHERE i.id IN ($placeholders)
            AND (SELECT COUNT(*) FROM picture_game_votes pgv WHERE pgv.winner_image_id = i.id) > 0
        ORDER BY game_wins DESC, score DESC, i.sort_order, i.filename
        LIMIT " . max(1, $limit));
    $stmt->execute($ids);
    return $stmt->fetchAll();
}

/**
 * Apply a cover image path from gallery.json after images have been scanned.
 */
function apply_gallery_cover_from_sidecar(array $gallery): void
{
    // Variable $metadata stores this steps working value.
    $metadata = read_gallery_sidecar(gallery_abs_path((string) $gallery['folder_path']) . DIRECTORY_SEPARATOR . 'gallery.json');
    if (empty($metadata['cover']) || !is_string($metadata['cover'])) {
        return;
    }
    try {
        // Variable $coverPath stores this steps working value.
        $coverPath = normalize_relative_path($metadata['cover']);
    } catch (RuntimeException) {
        return;
    }
    // Variable $image stores this steps working value.
    $image = find_image_by_path((int) $gallery['id'], $coverPath);
    if (!$image) {
        return;
    }
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare('UPDATE galleries SET cover_image_id = ?, updated_at = ? WHERE id = ?');
    $stmt->execute([(int) $image['id'], now_sql(), (int) $gallery['id']]);
}

/**
 * Fetch one gallery by numeric ID.
 */
function find_gallery(int $id): ?array
{
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare('SELECT * FROM galleries WHERE id = ?');
    $stmt->execute([$id]);
    // Variable $gallery stores this steps working value.
    $gallery = $stmt->fetch();
    return $gallery ?: null;
}

/**
 * Fetch one gallery by its public URL slug.
 */
function find_gallery_by_slug(string $slug): ?array
{
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare('SELECT * FROM galleries WHERE slug = ?');
    $stmt->execute([$slug]);
    // Variable $gallery stores this steps working value.
    $gallery = $stmt->fetch();
    return $gallery ?: null;
}

/**
 * Fetch one gallery by normalized folder path.
 */
function find_gallery_by_folder_path(string $folderPath): ?array
{
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare('SELECT * FROM galleries WHERE folder_path_hash = ?');
    $stmt->execute([hash('sha256', normalize_relative_path($folderPath))]);
    // Variable $gallery stores this steps working value.
    $gallery = $stmt->fetch();
    return $gallery ?: null;
}

/**
 * Find the nearest already-imported parent folder for a gallery path.
 */
function find_parent_gallery_for_path(string $folderPath): ?array
{
    // Variable $segments stores this steps working value.
    $segments = explode('/', normalize_relative_path($folderPath));
    while (count($segments) > 1) {
        array_pop($segments);
        // Variable $parent stores this steps working value.
        $parent = find_gallery_by_folder_path(implode('/', $segments));
        if ($parent) {
            return $parent;
        }
    }
    return null;
}

/**
 * Fetch one image by numeric ID.
 */
function find_image(int $id): ?array
{
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare('SELECT * FROM images WHERE id = ?');
    $stmt->execute([$id]);
    // Variable $image stores this steps working value.
    $image = $stmt->fetch();
    return $image ?: null;
}

/**
 * Fetch one image by gallery and normalized relative image path.
 */
function find_image_by_path(int $galleryId, string $relativePath): ?array
{
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare('SELECT * FROM images WHERE gallery_id = ? AND relative_path_hash = ?');
    $stmt->execute([$galleryId, hash('sha256', $relativePath)]);
    // Variable $image stores this steps working value.
    $image = $stmt->fetch();
    return $image ?: null;
}

/**
 * Fetch images for admin/public rendering, optionally public-only.
 */
function gallery_images(int $galleryId, bool $publicOnly): array
{
    // Variable $sql stores this steps working value.
    $sql = "SELECT * FROM images WHERE gallery_id = ? AND relative_path NOT LIKE '%/%'";
    if ($publicOnly) {
        $sql .= " AND visibility = 'public'";
    }
    $sql .= ' ORDER BY sort_order, filename';
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare($sql);
    $stmt->execute([$galleryId]);
    return $stmt->fetchAll();
}

/**
 * Sum all votes for an image.
 */
function vote_score(int $imageId): int
{
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare('SELECT COALESCE(SUM(vote), 0) FROM image_votes WHERE image_id = ?');
    $stmt->execute([$imageId]);
    return (int) $stmt->fetchColumn();
}

/**
 * Return the current logged-in user or visitor's vote for one image.
 */
function current_vote_for_image(int $imageId): int
{
    // Variable $user stores this steps working value.
    $user = current_user();
    if ($user) {
        // Variable $stmt stores this steps working value.
        $stmt = db()->prepare('SELECT vote FROM image_votes WHERE image_id = ? AND user_id = ?');
        $stmt->execute([$imageId, (int) $user['id']]);
        return (int) ($stmt->fetchColumn() ?: 0);
    }
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare('SELECT vote FROM image_votes WHERE image_id = ? AND visitor_hash = ?');
    $stmt->execute([$imageId, visitor_hash()]);
    return (int) ($stmt->fetchColumn() ?: 0);
}

/**
 * Parse admin-entered comma/semicolon/newline tag text into unique names.
 */
function split_tag_names(string $tags): array
{
    // Variable $names stores this steps working value.
    $names = [];
    foreach (preg_split('/[,;\n]+/', $tags) ?: [] as $name) {
        // Variable $name stores this steps working value.
        $name = trim($name);
        if ($name !== '') {
            $names[strtolower($name)] = substr($name, 0, 100);
        }
    }
    return array_values($names);
}

/**
 * Function `tag_slug` handles this scoped operation.
 */
function tag_slug(string $name): string
{
    // Variable $slug stores this steps working value.
    $slug = slugify($name);
    return $slug !== '' ? substr($slug, 0, 120) : 'tag';
}

/**
 * Return an existing tag ID or create a new tag row.
 */
function find_or_create_tag(string $name): int
{
    // Variable $slug stores this steps working value.
    $slug = tag_slug($name);
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare('SELECT id FROM tags WHERE slug = ?');
    $stmt->execute([$slug]);
    // Variable $existing stores this steps working value.
    $existing = $stmt->fetchColumn();
    if ($existing) {
        return (int) $existing;
    }
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare('INSERT INTO tags (name, slug, created_at, updated_at) VALUES (?, ?, ?, ?)');
    $stmt->execute([$name, $slug, now_sql(), now_sql()]);
    return (int) db()->lastInsertId();
}

/**
 * Replace all tags for one gallery or image with the submitted tag list.
 */
function sync_entity_tags(string $type, int $id, string $tagText): void
{
    // Variable $mapTable stores this steps working value.
    $mapTable = $type === 'gallery' ? 'gallery_tags' : 'image_tags';
    // Variable $idColumn stores this steps working value.
    $idColumn = $type === 'gallery' ? 'gallery_id' : 'image_id';
    db()->prepare('DELETE FROM ' . $mapTable . ' WHERE ' . $idColumn . ' = ?')->execute([$id]);
    foreach (split_tag_names($tagText) as $name) {
        // Variable $tagId stores this steps working value.
        $tagId = find_or_create_tag($name);
        // Variable $stmt stores this steps working value.
        $stmt = db()->prepare('INSERT IGNORE INTO ' . $mapTable . ' (' . $idColumn . ', tag_id) VALUES (?, ?)');
        $stmt->execute([$id, $tagId]);
    }
}

/**
 * Function `tags_for_entity` handles this scoped operation.
 */
function tags_for_entity(string $type, int $id): array
{
    // Variable $mapTable stores this steps working value.
    $mapTable = $type === 'gallery' ? 'gallery_tags' : 'image_tags';
    // Variable $idColumn stores this steps working value.
    $idColumn = $type === 'gallery' ? 'gallery_id' : 'image_id';
    try {
        // Variable $stmt stores this steps working value.
        $stmt = db()->prepare('SELECT t.* FROM tags t JOIN ' . $mapTable . ' mt ON mt.tag_id = t.id WHERE mt.' . $idColumn . ' = ? ORDER BY t.name');
        $stmt->execute([$id]);
        return $stmt->fetchAll();
    } catch (PDOException) {
        return [];
    }
}

/**
 * Function `tag_names_for_entity` handles this scoped operation.
 */
function tag_names_for_entity(string $type, int $id): string
{
    return implode(', ', array_column(tags_for_entity($type, $id), 'name'));
}

/**
 * Return all tag names for datalist suggestions in admin forms.
 */
function all_tag_names(): array
{
    try {
        return db()->query('SELECT name FROM tags ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException) {
        return [];
    }
}

/**
 * Fetch one tag by slug for public tag-filter pages.
 */
function find_tag_by_slug(string $slug): ?array
{
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare('SELECT * FROM tags WHERE slug = ?');
    $stmt->execute([$slug]);
    // Variable $tag stores this steps working value.
    $tag = $stmt->fetch();
    return $tag ?: null;
}

/**
 * Return public galleries that directly or indirectly contain a tag.
 */
function public_galleries_for_tag(int $tagId): array
{
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare("SELECT g.*, COUNT(i.id) AS image_count
        FROM galleries g
        LEFT JOIN images i ON i.gallery_id = g.id AND i.visibility = 'public' AND i.relative_path NOT LIKE '%/%'
        WHERE g.visibility = 'public' AND (
            EXISTS (SELECT 1 FROM gallery_tags gt WHERE gt.gallery_id = g.id AND gt.tag_id = ?)
            OR EXISTS (SELECT 1 FROM image_tags it JOIN images tagged_image ON tagged_image.id = it.image_id WHERE tagged_image.gallery_id = g.id AND it.tag_id = ?)
        )
        GROUP BY g.id
        ORDER BY g.sort_order, g.title");
    $stmt->execute([$tagId, $tagId]);
    return $stmt->fetchAll();
}

/**
 * Aggregate tags from descendant galleries and descendant images.
 */
function contained_tags_for_gallery(array $gallery, bool $publicOnly): array
{
    // Variable $folderPath stores this steps working value.
    $folderPath = normalize_relative_path((string) $gallery['folder_path']);
    if ($folderPath === '') {
        return [];
    }
    // Variable $visibilitySql stores this steps working value.
    $visibilitySql = $publicOnly ? " AND g.visibility = 'public'" : '';
    // Variable $imageVisibilitySql stores this steps working value.
    $imageVisibilitySql = $publicOnly ? " AND tagged_image.visibility = 'public'" : '';
    // Variable $sql stores this steps working value.
    $sql = "SELECT DISTINCT t.id, t.name, t.slug
        FROM tags t
        JOIN gallery_tags gt ON gt.tag_id = t.id
        JOIN galleries g ON g.id = gt.gallery_id
        WHERE g.folder_path LIKE ?" . $visibilitySql . "
        UNION
        SELECT DISTINCT t.id, t.name, t.slug
        FROM tags t
        JOIN image_tags it ON it.tag_id = t.id
        JOIN images tagged_image ON tagged_image.id = it.image_id
        JOIN galleries g ON g.id = tagged_image.gallery_id
        WHERE g.folder_path LIKE ?" . $visibilitySql . $imageVisibilitySql . "
        ORDER BY name";
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare($sql);
    $stmt->execute([$folderPath . '/%', $folderPath . '/%']);
    return $stmt->fetchAll();
}

/**
 * Read one application setting with a fallback.
 */
function app_setting(string $key, ?string $default = null): ?string
{
    try {
        // Variable $stmt stores this steps working value.
        $stmt = db()->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
        $stmt->execute([$key]);
        // Variable $value stores this steps working value.
        $value = $stmt->fetchColumn();
        return $value === false ? $default : (string) $value;
    } catch (PDOException) {
        return $default;
    }
}

/**
 * Upsert one application setting.
 */
function set_app_setting(string $key, string $value): void
{
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare('INSERT INTO app_settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at)');
    $stmt->execute([$key, $value, now_sql()]);
}

/**
 * Public site name shown in the header and browser title.
 */
function site_name(): string
{
    // Variable $name stores this steps working value.
    $name = trim((string) app_setting('site_name', 'Gallery CMS'));
    return $name !== '' ? $name : 'Gallery CMS';
}

/**
 * Return gallery IDs whose admin tree rows should start collapsed.
 */
function collapsed_gallery_ids(): array
{
    // Variable $decoded stores this steps working value.
    $decoded = json_decode((string) app_setting('admin_collapsed_gallery_ids', '[]'), true);
    if (!is_array($decoded)) {
        return [];
    }
    return array_values(array_unique(array_map('intval', $decoded)));
}

/**
 * Persist the admin gallery tree collapse state.
 */
function set_collapsed_gallery_ids(array $ids): void
{
    // Variable $ids stores this steps working value.
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
    set_app_setting('admin_collapsed_gallery_ids', json_encode($ids, JSON_THROW_ON_ERROR));
}

/**
 * Theme settings are stored in the DB so the visual preset can be changed
 * without editing PHP or CSS files.
 */
function theme_settings(): array
{
    return [
        'accent' => app_setting('theme_accent', '#a5481c'),
        'accent_dark' => app_setting('theme_accent_dark', '#713414'),
        'paper' => app_setting('theme_paper', '#f8f4ec'),
        'panel' => app_setting('theme_panel', '#fffaf0'),
        'radius' => app_setting('theme_radius', '16'),
        'font' => app_setting('theme_font', 'serif'),
    ];
}

/**
 * Function `custom_css_path` handles this scoped operation.
 */
function custom_css_path(): string
{
    return dirname(__DIR__) . '/public/assets/custom.css';
}

/**
 * Return the folder containing selectable custom CSS skins.
 */
function custom_css_preset_dir(): string
{
    return dirname(__DIR__) . '/custom_css';
}

/**
 * Return selectable custom CSS files from the preset folder.
 */
function custom_css_presets(): array
{
    // Variable $dir stores this steps working value.
    $dir = custom_css_preset_dir();
    if (!is_dir($dir)) {
        return [];
    }
    // Variable $files stores this steps working value.
    $files = glob($dir . '/*.css') ?: [];
    sort($files, SORT_NATURAL | SORT_FLAG_CASE);
    // Variable $presets stores this steps working value.
    $presets = [];
    foreach ($files as $file) {
        $presets[basename($file)] = $file;
    }
    return $presets;
}

/**
 * Resolve one preset filename to a path inside the custom CSS preset folder.
 */
function custom_css_preset_path(string $filename): ?string
{
    if ($filename === '' || basename($filename) !== $filename || !str_ends_with(strtolower($filename), '.css')) {
        return null;
    }
    // Variable $presets stores this steps working value.
    $presets = custom_css_presets();
    return $presets[$filename] ?? null;
}

/**
 * Return the custom CSS URL only when a custom file exists.
 */
function custom_css_url(): ?string
{
    return is_file(custom_css_path()) ? asset_url('assets/custom.css') : null;
}

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
 * Build a content signature for one gallery ZIP cache entry.
 */
function gallery_zip_signature(int $galleryId, bool $publicOnly): string
{
    // Variable $sql stores this steps working value.
    $sql = "SELECT relative_path, file_size, modified_at FROM images WHERE gallery_id = ? AND relative_path NOT LIKE '%/%'";
    // Variable $params stores this steps working value.
    $params = [$galleryId];
    if ($publicOnly) {
        $sql .= " AND visibility = 'public'";
    }
    $sql .= ' ORDER BY relative_path';
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return hash('sha256', json_encode($stmt->fetchAll(), JSON_UNESCAPED_SLASHES));
}

/**
 * Build a content signature for the admin "all galleries" ZIP cache entry.
 */
function all_zip_signature(): string
{
    // Variable $rows stores this steps working value.
    $rows = db()->query("SELECT g.folder_path, i.relative_path, i.file_size, i.modified_at FROM images i JOIN galleries g ON g.id = i.gallery_id WHERE i.relative_path NOT LIKE '%/%' ORDER BY g.folder_path, i.relative_path")->fetchAll();
    return hash('sha256', json_encode($rows, JSON_UNESCAPED_SLASHES));
}

/**
 * Create or reuse a ZIP archive for one gallery.
 */
function build_gallery_zip(int $galleryId, bool $publicOnly): string
{
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
    if ($cached && is_file((string) $cached['file_path'])) {
        return (string) $cached['file_path'];
    }

    // Variable $filePath stores this steps working value.
    $filePath = zip_cache_dir() . DIRECTORY_SEPARATOR . 'gallery-' . $galleryId . '-' . $signature . '.zip';
    create_zip($filePath, gallery_zip_files($gallery, $publicOnly));
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
    // Variable $signature stores this steps working value.
    $signature = all_zip_signature();
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare('SELECT * FROM zip_archives WHERE scope = ? AND gallery_id IS NULL AND content_signature = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute(['all', $signature]);
    // Variable $cached stores this steps working value.
    $cached = $stmt->fetch();
    if ($cached && is_file((string) $cached['file_path'])) {
        return (string) $cached['file_path'];
    }

    // Variable $galleries stores this steps working value.
    $galleries = db()->query('SELECT * FROM galleries ORDER BY folder_path')->fetchAll();
    // Variable $files stores this steps working value.
    $files = [];
    foreach ($galleries as $gallery) {
        foreach (gallery_zip_files($gallery, false) as $file) {
            $files[] = $file;
        }
    }
    // Variable $filePath stores this steps working value.
    $filePath = zip_cache_dir() . DIRECTORY_SEPARATOR . 'all-' . $signature . '.zip';
    create_zip($filePath, $files);
    // Variable $insert stores this steps working value.
    $insert = db()->prepare('INSERT INTO zip_archives (scope, gallery_id, file_path, content_signature, created_at, updated_at) VALUES (?, NULL, ?, ?, ?, ?)');
    $insert->execute(['all', $filePath, $signature, now_sql(), now_sql()]);
    return $filePath;
}

/**
 * Produce the list of files that should be stored in a gallery ZIP.
 */
function gallery_zip_files(array $gallery, bool $publicOnly): array
{
    // Variable $sql stores this steps working value.
    $sql = "SELECT * FROM images WHERE gallery_id = ? AND relative_path NOT LIKE '%/%'";
    // Variable $params stores this steps working value.
    $params = [(int) $gallery['id']];
    if ($publicOnly) {
        $sql .= " AND visibility = 'public'";
    }
    $sql .= ' ORDER BY sort_order, filename';
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    // Variable $files stores this steps working value.
    $files = [];
    foreach ($stmt->fetchAll() as $image) {
        // Variable $absolute stores this steps working value.
        $absolute = image_abs_path($image, $gallery);
        if (is_file($absolute)) {
            $files[] = [
                'absolute' => $absolute,
                'zip_path' => normalize_relative_path($gallery['folder_path'] . '/' . $image['relative_path']),
            ];
        }
    }
    return $files;
}

/**
 * Write a ZIP archive to disk.
 */
function create_zip(string $filePath, array $files): void
{
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('ZipArchive is not available.');
    }
    // Variable $zip stores this steps working value.
    $zip = new ZipArchive();
    if ($zip->open($filePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to create ZIP archive.');
    }
    foreach ($files as $file) {
        $zip->addFile($file['absolute'], $file['zip_path']);
        $zip->setCompressionName($file['zip_path'], ZipArchive::CM_STORE);
    }
    $zip->close();
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
