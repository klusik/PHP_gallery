<?php

declare(strict_types=1);

namespace Gallery\Tests {
    final class FakeStatement
    {
        private string $sql;
        private array $params = [];

        public function __construct(string $sql) { $this->sql = $sql; }
        public function execute(array $params = []): bool { $this->params = $params; return true; }
        public function fetchAll(): array
        {
            if (str_contains($this->sql, 'FROM galleries WHERE folder_path')) {
                $folder = (string) ($this->params[0] ?? '');
                $prefix = rtrim((string) ($this->params[1] ?? ''), '%');
                $rows = array_values(array_filter($GLOBALS['gallery_download_test_galleries'], static function (array $row) use ($folder, $prefix): bool {
                    return $row['folder_path'] === $folder || str_starts_with($row['folder_path'], $prefix);
                }));
                usort($rows, static fn (array $a, array $b): int => [strlen($a['folder_path']), $a['folder_path'], $a['id']] <=> [strlen($b['folder_path']), $b['folder_path'], $b['id']]);
                return $rows;
            }
            if (str_contains($this->sql, 'FROM images WHERE gallery_id IN')) {
                $ids = array_map('intval', $this->params);
                $rows = array_values(array_filter($GLOBALS['gallery_download_test_images'], static fn (array $row): bool => in_array((int) $row['gallery_id'], $ids, true) && $row['visibility'] === 'public'));
                usort($rows, static fn (array $a, array $b): int => [(int) $a['gallery_id'], (int) $a['sort_order'], $a['filename'], $a['relative_path'], (int) $a['id']] <=> [(int) $b['gallery_id'], (int) $b['sort_order'], $b['filename'], $b['relative_path'], (int) $b['id']]);
                return $rows;
            }
            throw new \RuntimeException('Unexpected SQL in gallery download test: ' . $this->sql);
        }
    }

    final class FakeDb
    {
        public function prepare(string $sql): FakeStatement { return new FakeStatement($sql); }
    }
}

namespace Gallery\Core {
    function db(): \Gallery\Tests\FakeDb { return new \Gallery\Tests\FakeDb(); }
    function cms_config(): array { return ['zip_cache_path' => sys_get_temp_dir()]; }
    function now_sql(): string { return '2026-08-31 12:00:00'; }
    function normalize_relative_path(string $path): string
    {
        $segments = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '' || $segment === '.') continue;
            if ($segment === '..') throw new \RuntimeException('Invalid relative path.');
            $segments[] = $segment;
        }
        return implode('/', $segments);
    }
    function path_inside(string $root, string $path): bool
    {
        $rootReal = realpath($root); $pathReal = realpath($path);
        return $rootReal !== false && $pathReal !== false && ($pathReal === $rootReal || str_starts_with($pathReal, rtrim($rootReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR));
    }
    function slugify(string $value): string { return strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $value) ?? '', '-')); }
    function image_public_asset_version(array $image): string { return 'v' . (int) $image['id']; }
    function url_for(string $page, array $params = []): string { return '/index.php?' . http_build_query(['page' => $page] + $params); }
}

namespace Gallery\Services {
    function find_gallery(int $id): ?array { foreach ($GLOBALS['gallery_download_test_galleries'] as $row) if ((int) $row['id'] === $id) return $row; return null; }
    function find_image(int $id): ?array { foreach ($GLOBALS['gallery_download_test_images'] as $row) if ((int) $row['id'] === $id) return $row; return null; }
    function gallery_zip_signature(int $galleryId, bool $publicOnly): string { return 'unused'; }
    function image_abs_path(array $image, array $gallery): string { return (string) ($image['_path'] ?? ''); }
    function gallery_abs_path(string $folderPath): string { return (string) ($GLOBALS['gallery_download_test_roots'][$folderPath] ?? ''); }
    function image_public_display_file(array $image, array $gallery, bool $allow = true): ?array { return null; }
    function picture_manager_normalize_image_ids(array $ids): array { return $ids; }
    function picture_manager_owned_images_for_selection(int $id, array $ids, array &$failures): array { return []; }
    function public_image_visible_to_current_visitor(array $image, array $gallery): bool { return ($image['visibility'] ?? '') === 'public' && visitor_can_access_gallery($gallery); }
    function t(string $key, string $fallback, array $replace = []): string { return $fallback; }
    function visitor_can_access_gallery(array $gallery): bool { return !empty($gallery['allowed']); }

    require_once dirname(__DIR__) . '/app/services/downloads.php';
}

namespace Gallery\Tests {
    use Gallery\Services\GalleryDownloadManifestException;
    use function Gallery\Services\gallery_download_authorized_source;
    use function Gallery\Services\gallery_download_manifest;

    function expect(bool $condition, string $message): void
    {
        if (!$condition) { fwrite(STDERR, $message . "\n"); exit(1); }
    }

    $base = sys_get_temp_dir() . '/gallery-download-manifest-test-' . bin2hex(random_bytes(4));
    mkdir($base, 0777, true);
    mkdir($base . '/root', 0777, true);
    mkdir($base . '/root/child', 0777, true);
    mkdir($base . '/other', 0777, true);
    file_put_contents($base . '/root/a.jpg', 'AAAA');
    file_put_contents($base . '/root/b.jpg', 'BBBBBB');
    file_put_contents($base . '/root/dup2.jpg', 'CC');
    file_put_contents($base . '/root/child/c.jpg', 'DDD');
    file_put_contents($base . '/other/x.jpg', 'XXXX');

    $GLOBALS['gallery_download_test_galleries'] = [
        ['id' => 1, 'folder_path' => 'root', 'title' => 'Root Gallery', 'allowed' => true],
        ['id' => 2, 'folder_path' => 'root/child', 'title' => 'Child', 'allowed' => true],
        ['id' => 3, 'folder_path' => 'other', 'title' => 'Other', 'allowed' => true],
        ['id' => 4, 'folder_path' => 'private', 'title' => 'Private', 'allowed' => false],
    ];
    $GLOBALS['gallery_download_test_roots'] = [
        'root' => $base . '/root', 'root/child' => $base . '/root/child', 'other' => $base . '/other', 'private' => $base . '/private',
    ];
    $GLOBALS['gallery_download_test_images'] = [
        ['id' => 10, 'gallery_id' => 1, 'visibility' => 'public', 'sort_order' => 2, 'filename' => 'b.jpg', 'relative_path' => 'b.jpg', '_path' => $base . '/root/b.jpg'],
        ['id' => 11, 'gallery_id' => 1, 'visibility' => 'public', 'sort_order' => 1, 'filename' => 'a.jpg', 'relative_path' => 'a.jpg', '_path' => $base . '/root/a.jpg'],
        ['id' => 12, 'gallery_id' => 1, 'visibility' => 'public', 'sort_order' => 1, 'filename' => 'zdup.jpg', 'relative_path' => 'a.jpg', '_path' => $base . '/root/dup2.jpg'],
        ['id' => 13, 'gallery_id' => 2, 'visibility' => 'public', 'sort_order' => 1, 'filename' => 'c.jpg', 'relative_path' => 'c.jpg', '_path' => $base . '/root/child/c.jpg'],
        ['id' => 14, 'gallery_id' => 3, 'visibility' => 'public', 'sort_order' => 1, 'filename' => 'x.jpg', 'relative_path' => 'x.jpg', '_path' => $base . '/other/x.jpg'],
        ['id' => 15, 'gallery_id' => 1, 'visibility' => 'private', 'sort_order' => 0, 'filename' => 'private.jpg', 'relative_path' => 'private.jpg', '_path' => $base . '/root/a.jpg'],
    ];

    try {
        $manifest = gallery_download_manifest($GLOBALS['gallery_download_test_galleries'][0]);
        expect($manifest['ok'] === true, 'Public authorized manifest should succeed.');
        expect($manifest['total_files'] === 4, 'Manifest should contain only public authorized subtree images.');
        expect($manifest['total_bytes'] === 15, 'Manifest total bytes should equal current source sizes.');
        expect(array_column($manifest['files'], 'name') === ['root/a.jpg', 'root/a-2.jpg', 'root/b.jpg', 'root/child/c.jpg'], 'Manifest ordering and duplicate naming should be deterministic.');
        expect(!str_contains(json_encode($manifest), $base), 'Manifest must not expose absolute filesystem paths.');
        expect(str_contains($manifest['files'][0]['url'], 'download_gallery_file'), 'Manifest should expose only the bounded media route.');

        expect(gallery_download_authorized_source(1, 11) !== null, 'Authorized manifest source should resolve.');
        expect(gallery_download_authorized_source(1, 14) === null, 'Changing image ID must not escape the requested gallery subtree.');
        expect(gallery_download_authorized_source(1, 15) === null, 'Private image ID must not become downloadable.');

        $denied = $GLOBALS['gallery_download_test_galleries'][3];
        $thrown = false;
        try { gallery_download_manifest($denied); } catch (GalleryDownloadManifestException) { $thrown = true; }
        expect($thrown, 'Password/private-style denied gallery must not produce a manifest.');

        $GLOBALS['gallery_download_test_images'][0]['_path'] = $base . '/root/missing.jpg';
        $thrown = false;
        try { gallery_download_manifest($GLOBALS['gallery_download_test_galleries'][0]); } catch (GalleryDownloadManifestException) { $thrown = true; }
        expect($thrown, 'Missing source must fail the manifest rather than silently omit the file.');

        echo "gallery_download_manifest_test: ok\n";
    } finally {
        foreach ([$base . '/root/child/c.jpg', $base . '/root/a.jpg', $base . '/root/b.jpg', $base . '/root/dup2.jpg', $base . '/other/x.jpg'] as $path) @unlink($path);
        @rmdir($base . '/root/child'); @rmdir($base . '/root'); @rmdir($base . '/other'); @rmdir($base);
    }
}
