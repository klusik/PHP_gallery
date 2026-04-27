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
    $relativePath = normalize_relative_path($relativePath);
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
    $galleryRoot = gallery_abs_path((string) $gallery['folder_path']);
    $path = $galleryRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, normalize_relative_path((string) $image['relative_path']));
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
    $root = galleries_root();
    if (!is_dir($root)) {
        return [];
    }

    $pdo = db();
    $known = $pdo->query('SELECT folder_path FROM galleries')->fetchAll(PDO::FETCH_COLUMN);
    $known = array_flip($known);
    $candidates = [];
    $ignoreNames = ['cache', 'thumbs', 'thumbnail', 'thumbnails', 'preview', 'previews'];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            function (SplFileInfo $file) use ($ignoreNames): bool {
                if (!$file->isDir()) {
                    return true;
                }
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
        $relative = normalize_relative_path(substr($item->getPathname(), strlen($root)));
        if ($relative === '' || isset($known[$relative])) {
            continue;
        }
        $hasImages = false;
        foreach (new DirectoryIterator($item->getPathname()) as $child) {
            if ($child->isFile() && is_supported_image_path($child->getFilename())) {
                $hasImages = true;
                break;
            }
        }
        $hasDescendantImages = false;
        if (!$hasImages) {
            $descendants = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($item->getPathname(), FilesystemIterator::SKIP_DOTS));
            foreach ($descendants as $descendant) {
                if ($descendant->isFile() && is_supported_image_path($descendant->getFilename())) {
                    $hasDescendantImages = true;
                    break;
                }
            }
        }
        $jsonPath = $item->getPathname() . DIRECTORY_SEPARATOR . 'gallery.json';
        if ($hasImages || $hasDescendantImages || is_file($jsonPath)) {
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
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

/**
 * Persist editable gallery metadata back into gallery.json.
 */
function write_gallery_sidecar(array $gallery): void
{
    $path = gallery_abs_path((string) $gallery['folder_path']) . DIRECTORY_SEPARATOR . 'gallery.json';
    $data = [
        'title' => $gallery['title'],
        'description' => $gallery['description'],
        'visibility' => $gallery['visibility'],
        'sort_order' => (int) $gallery['sort_order'],
    ];
    if (!empty($gallery['cover_image_id'])) {
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
function import_galleries(array $folderPaths): int
{
    $pdo = db();
    $candidates = [];
    foreach (discover_gallery_candidates() as $candidate) {
        $candidates[$candidate['folder_path']] = $candidate;
    }
    $count = 0;
    usort($folderPaths, static fn ($a, $b): int => substr_count((string) $a, '/') <=> substr_count((string) $b, '/'));
    foreach ($folderPaths as $folderPath) {
        $folderPath = normalize_relative_path((string) $folderPath);
        if (!isset($candidates[$folderPath])) {
            continue;
        }
        $candidate = $candidates[$folderPath];
        $visibility = in_array($candidate['visibility'], ['draft', 'public', 'private'], true) ? $candidate['visibility'] : 'draft';
        $parent = find_parent_gallery_for_path($folderPath);
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
        $gallery = find_gallery((int) $pdo->lastInsertId());
        if ($gallery) {
            write_gallery_sidecar($gallery);
        }
        $count++;
    }
    sync_gallery_parent_ids();
    return $count;
}

/**
 * Import/update image rows for images directly inside one gallery folder.
 *
 * Child-folder images are intentionally ignored here because child folders are
 * represented as subgalleries with their own scans.
 */
function scan_gallery_images(int $galleryId): int
{
    $gallery = find_gallery($galleryId);
    if (!$gallery) {
        return 0;
    }
    $root = gallery_abs_path((string) $gallery['folder_path']);
    if (!is_dir($root)) {
        return 0;
    }

    $pdo = db();
    $count = 0;
    foreach (new DirectoryIterator($root) as $file) {
        if (!$file->isFile() || !is_supported_image_path($file->getFilename())) {
            continue;
        }
        $relative = normalize_relative_path(substr($file->getPathname(), strlen($root)));
        $info = @getimagesize($file->getPathname());
        if ($info === false || empty($info['mime']) || !str_starts_with((string) $info['mime'], 'image/')) {
            continue;
        }
        $modifiedAt = date('Y-m-d H:i:s', $file->getMTime());
        $existing = find_image_by_path($galleryId, $relative);
        if (!$existing) {
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
 * Pick the first direct image as cover when the gallery has no explicit cover.
 */
function ensure_gallery_cover(int $galleryId): void
{
    $gallery = find_gallery($galleryId);
    if (!$gallery || !empty($gallery['cover_image_id'])) {
        return;
    }
    $stmt = db()->prepare("SELECT id FROM images WHERE gallery_id = ? AND relative_path NOT LIKE '%/%' ORDER BY CASE WHEN visibility = 'public' THEN 0 ELSE 1 END, sort_order, filename LIMIT 1");
    $stmt->execute([$galleryId]);
    $coverId = $stmt->fetchColumn();
    if (!$coverId) {
        return;
    }
    $update = db()->prepare('UPDATE galleries SET cover_image_id = ?, updated_at = ? WHERE id = ?');
    $update->execute([(int) $coverId, now_sql(), $galleryId]);
}

/**
 * Rebuild parent_id links from filesystem folder nesting.
 */
function sync_gallery_parent_ids(): void
{
    $galleries = db()->query('SELECT id, folder_path FROM galleries ORDER BY folder_path')->fetchAll();
    foreach ($galleries as $gallery) {
        $parent = find_parent_gallery_for_path((string) $gallery['folder_path']);
        $parentId = $parent ? (int) $parent['id'] : null;
        if ($parentId === null) {
            $stmt = db()->prepare('UPDATE galleries SET parent_id = NULL, updated_at = ? WHERE id = ? AND parent_id IS NOT NULL');
            $stmt->execute([now_sql(), (int) $gallery['id']]);
            continue;
        }
        $stmt = db()->prepare('UPDATE galleries SET parent_id = ?, updated_at = ? WHERE id = ? AND (parent_id IS NULL OR parent_id <> ?)');
        $stmt->execute([$parentId, now_sql(), (int) $gallery['id'], $parentId]);
    }
}

/**
 * Return direct child galleries for a parent gallery.
 */
function child_galleries(int $parentId, bool $publicOnly): array
{
    $sql = "SELECT g.*, COUNT(i.id) AS image_count
        FROM galleries g
        LEFT JOIN images i ON i.gallery_id = g.id AND i.visibility = 'public' AND i.relative_path NOT LIKE '%/%'
        WHERE g.parent_id = ?";
    $params = [$parentId];
    if ($publicOnly) {
        $sql .= " AND g.visibility = 'public'";
    }
    $sql .= ' GROUP BY g.id ORDER BY g.sort_order, g.title';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Walk from a gallery to its root ancestors for breadcrumb rendering.
 */
function gallery_ancestors(array $gallery, bool $publicOnly): array
{
    $ancestors = [];
    $parentId = $gallery['parent_id'] ?? null;
    while ($parentId) {
        $parent = find_gallery((int) $parentId);
        if (!$parent || ($publicOnly && $parent['visibility'] !== 'public')) {
            break;
        }
        array_unshift($ancestors, $parent);
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
    $gallery = find_gallery($galleryId);
    if (!$gallery) {
        return null;
    }
    if (!empty($gallery['cover_image_id'])) {
        $cover = find_image((int) $gallery['cover_image_id']);
        if ($cover && !str_contains((string) $cover['relative_path'], '/') && (!$publicOnly || $cover['visibility'] === 'public')) {
            return $cover;
        }
    }
    $sql = "SELECT * FROM images WHERE gallery_id = ? AND relative_path NOT LIKE '%/%'";
    if ($publicOnly) {
        $sql .= " AND visibility = 'public'";
    }
    $sql .= " ORDER BY CASE WHEN visibility = 'public' THEN 0 ELSE 1 END, sort_order, filename LIMIT 1";
    $stmt = db()->prepare($sql);
    $stmt->execute([$galleryId]);
    $image = $stmt->fetch();
    return $image ?: null;
}

function gallery_cover_collage_images(int $galleryId, bool $publicOnly, int $limit = 4): array
{
    $images = [];
    // Parent galleries without direct images borrow covers from child galleries.
    foreach (child_galleries($galleryId, $publicOnly) as $child) {
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
 * Apply a cover image path from gallery.json after images have been scanned.
 */
function apply_gallery_cover_from_sidecar(array $gallery): void
{
    $metadata = read_gallery_sidecar(gallery_abs_path((string) $gallery['folder_path']) . DIRECTORY_SEPARATOR . 'gallery.json');
    if (empty($metadata['cover']) || !is_string($metadata['cover'])) {
        return;
    }
    try {
        $coverPath = normalize_relative_path($metadata['cover']);
    } catch (RuntimeException) {
        return;
    }
    $image = find_image_by_path((int) $gallery['id'], $coverPath);
    if (!$image) {
        return;
    }
    $stmt = db()->prepare('UPDATE galleries SET cover_image_id = ?, updated_at = ? WHERE id = ?');
    $stmt->execute([(int) $image['id'], now_sql(), (int) $gallery['id']]);
}

/**
 * Fetch one gallery by numeric ID.
 */
function find_gallery(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM galleries WHERE id = ?');
    $stmt->execute([$id]);
    $gallery = $stmt->fetch();
    return $gallery ?: null;
}

/**
 * Fetch one gallery by its public URL slug.
 */
function find_gallery_by_slug(string $slug): ?array
{
    $stmt = db()->prepare('SELECT * FROM galleries WHERE slug = ?');
    $stmt->execute([$slug]);
    $gallery = $stmt->fetch();
    return $gallery ?: null;
}

/**
 * Fetch one gallery by normalized folder path.
 */
function find_gallery_by_folder_path(string $folderPath): ?array
{
    $stmt = db()->prepare('SELECT * FROM galleries WHERE folder_path_hash = ?');
    $stmt->execute([hash('sha256', normalize_relative_path($folderPath))]);
    $gallery = $stmt->fetch();
    return $gallery ?: null;
}

/**
 * Find the nearest already-imported parent folder for a gallery path.
 */
function find_parent_gallery_for_path(string $folderPath): ?array
{
    $segments = explode('/', normalize_relative_path($folderPath));
    while (count($segments) > 1) {
        array_pop($segments);
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
    $stmt = db()->prepare('SELECT * FROM images WHERE id = ?');
    $stmt->execute([$id]);
    $image = $stmt->fetch();
    return $image ?: null;
}

/**
 * Fetch one image by gallery and normalized relative image path.
 */
function find_image_by_path(int $galleryId, string $relativePath): ?array
{
    $stmt = db()->prepare('SELECT * FROM images WHERE gallery_id = ? AND relative_path_hash = ?');
    $stmt->execute([$galleryId, hash('sha256', $relativePath)]);
    $image = $stmt->fetch();
    return $image ?: null;
}

/**
 * Sum all votes for an image.
 */
function vote_score(int $imageId): int
{
    $stmt = db()->prepare('SELECT COALESCE(SUM(vote), 0) FROM image_votes WHERE image_id = ?');
    $stmt->execute([$imageId]);
    return (int) $stmt->fetchColumn();
}

/**
 * Return the current logged-in user or visitor's vote for one image.
 */
function current_vote_for_image(int $imageId): int
{
    $user = current_user();
    if ($user) {
        $stmt = db()->prepare('SELECT vote FROM image_votes WHERE image_id = ? AND user_id = ?');
        $stmt->execute([$imageId, (int) $user['id']]);
        return (int) ($stmt->fetchColumn() ?: 0);
    }
    $stmt = db()->prepare('SELECT vote FROM image_votes WHERE image_id = ? AND visitor_hash = ?');
    $stmt->execute([$imageId, visitor_hash()]);
    return (int) ($stmt->fetchColumn() ?: 0);
}

/**
 * Parse admin-entered comma/semicolon/newline tag text into unique names.
 */
function split_tag_names(string $tags): array
{
    $names = [];
    foreach (preg_split('/[,;\n]+/', $tags) ?: [] as $name) {
        $name = trim($name);
        if ($name !== '') {
            $names[strtolower($name)] = substr($name, 0, 100);
        }
    }
    return array_values($names);
}

function tag_slug(string $name): string
{
    $slug = slugify($name);
    return $slug !== '' ? substr($slug, 0, 120) : 'tag';
}

/**
 * Return an existing tag ID or create a new tag row.
 */
function find_or_create_tag(string $name): int
{
    $slug = tag_slug($name);
    $stmt = db()->prepare('SELECT id FROM tags WHERE slug = ?');
    $stmt->execute([$slug]);
    $existing = $stmt->fetchColumn();
    if ($existing) {
        return (int) $existing;
    }
    $stmt = db()->prepare('INSERT INTO tags (name, slug, created_at, updated_at) VALUES (?, ?, ?, ?)');
    $stmt->execute([$name, $slug, now_sql(), now_sql()]);
    return (int) db()->lastInsertId();
}

/**
 * Replace all tags for one gallery or image with the submitted tag list.
 */
function sync_entity_tags(string $type, int $id, string $tagText): void
{
    $mapTable = $type === 'gallery' ? 'gallery_tags' : 'image_tags';
    $idColumn = $type === 'gallery' ? 'gallery_id' : 'image_id';
    db()->prepare('DELETE FROM ' . $mapTable . ' WHERE ' . $idColumn . ' = ?')->execute([$id]);
    foreach (split_tag_names($tagText) as $name) {
        $tagId = find_or_create_tag($name);
        $stmt = db()->prepare('INSERT IGNORE INTO ' . $mapTable . ' (' . $idColumn . ', tag_id) VALUES (?, ?)');
        $stmt->execute([$id, $tagId]);
    }
}

function tags_for_entity(string $type, int $id): array
{
    $mapTable = $type === 'gallery' ? 'gallery_tags' : 'image_tags';
    $idColumn = $type === 'gallery' ? 'gallery_id' : 'image_id';
    try {
        $stmt = db()->prepare('SELECT t.* FROM tags t JOIN ' . $mapTable . ' mt ON mt.tag_id = t.id WHERE mt.' . $idColumn . ' = ? ORDER BY t.name');
        $stmt->execute([$id]);
        return $stmt->fetchAll();
    } catch (PDOException) {
        return [];
    }
}

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
    $stmt = db()->prepare('SELECT * FROM tags WHERE slug = ?');
    $stmt->execute([$slug]);
    $tag = $stmt->fetch();
    return $tag ?: null;
}

/**
 * Return public galleries that directly or indirectly contain a tag.
 */
function public_galleries_for_tag(int $tagId): array
{
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
    $folderPath = normalize_relative_path((string) $gallery['folder_path']);
    if ($folderPath === '') {
        return [];
    }
    $visibilitySql = $publicOnly ? " AND g.visibility = 'public'" : '';
    $imageVisibilitySql = $publicOnly ? " AND tagged_image.visibility = 'public'" : '';
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
        $stmt = db()->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
        $stmt->execute([$key]);
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
    $stmt = db()->prepare('INSERT INTO app_settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at)');
    $stmt->execute([$key, $value, now_sql()]);
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

function custom_css_path(): string
{
    return dirname(__DIR__) . '/public/assets/custom.css';
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
    $sql = "SELECT relative_path, file_size, modified_at FROM images WHERE gallery_id = ? AND relative_path NOT LIKE '%/%'";
    $params = [$galleryId];
    if ($publicOnly) {
        $sql .= " AND visibility = 'public'";
    }
    $sql .= ' ORDER BY relative_path';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return hash('sha256', json_encode($stmt->fetchAll(), JSON_UNESCAPED_SLASHES));
}

/**
 * Build a content signature for the admin "all galleries" ZIP cache entry.
 */
function all_zip_signature(): string
{
    $rows = db()->query("SELECT g.folder_path, i.relative_path, i.file_size, i.modified_at FROM images i JOIN galleries g ON g.id = i.gallery_id WHERE i.relative_path NOT LIKE '%/%' ORDER BY g.folder_path, i.relative_path")->fetchAll();
    return hash('sha256', json_encode($rows, JSON_UNESCAPED_SLASHES));
}

/**
 * Create or reuse a ZIP archive for one gallery.
 */
function build_gallery_zip(int $galleryId, bool $publicOnly): string
{
    $gallery = find_gallery($galleryId);
    if (!$gallery) {
        throw new RuntimeException('Gallery not found.');
    }
    $signature = gallery_zip_signature($galleryId, $publicOnly);
    $scope = 'gallery';
    $stmt = db()->prepare('SELECT * FROM zip_archives WHERE scope = ? AND gallery_id = ? AND content_signature = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$scope, $galleryId, $signature]);
    $cached = $stmt->fetch();
    if ($cached && is_file((string) $cached['file_path'])) {
        return (string) $cached['file_path'];
    }

    $filePath = zip_cache_dir() . DIRECTORY_SEPARATOR . 'gallery-' . $galleryId . '-' . $signature . '.zip';
    create_zip($filePath, gallery_zip_files($gallery, $publicOnly));
    $insert = db()->prepare('INSERT INTO zip_archives (scope, gallery_id, file_path, content_signature, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)');
    $insert->execute([$scope, $galleryId, $filePath, $signature, now_sql(), now_sql()]);
    return $filePath;
}

/**
 * Create or reuse a ZIP archive containing every imported gallery.
 */
function build_all_zip(): string
{
    $signature = all_zip_signature();
    $stmt = db()->prepare('SELECT * FROM zip_archives WHERE scope = ? AND gallery_id IS NULL AND content_signature = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute(['all', $signature]);
    $cached = $stmt->fetch();
    if ($cached && is_file((string) $cached['file_path'])) {
        return (string) $cached['file_path'];
    }

    $galleries = db()->query('SELECT * FROM galleries ORDER BY folder_path')->fetchAll();
    $files = [];
    foreach ($galleries as $gallery) {
        foreach (gallery_zip_files($gallery, false) as $file) {
            $files[] = $file;
        }
    }
    $filePath = zip_cache_dir() . DIRECTORY_SEPARATOR . 'all-' . $signature . '.zip';
    create_zip($filePath, $files);
    $insert = db()->prepare('INSERT INTO zip_archives (scope, gallery_id, file_path, content_signature, created_at, updated_at) VALUES (?, NULL, ?, ?, ?, ?)');
    $insert->execute(['all', $filePath, $signature, now_sql(), now_sql()]);
    return $filePath;
}

/**
 * Produce the list of files that should be stored in a gallery ZIP.
 */
function gallery_zip_files(array $gallery, bool $publicOnly): array
{
    $sql = "SELECT * FROM images WHERE gallery_id = ? AND relative_path NOT LIKE '%/%'";
    $params = [(int) $gallery['id']];
    if ($publicOnly) {
        $sql .= " AND visibility = 'public'";
    }
    $sql .= ' ORDER BY sort_order, filename';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $files = [];
    foreach ($stmt->fetchAll() as $image) {
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
