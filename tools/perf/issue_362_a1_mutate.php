<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tools/perf/issue_362_a1_mutate.php
 * Module Type: Disposable A1 Fixture Mutation
 * Purpose: Verify actual scanner/deletion lifecycle inside one owned album.
 * Responsibilities:
 *   - Authenticate exact disposable gallery-workflow ownership.
 *   - Replace or add one synthetically generated JPEG using the real scanner.
 *   - Remove one synthetic JPEG through the actual Admin deletion owner.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/tests/support/gallery_workflow_safety.php';

/**
 * Keep mutation image width comparable to the original seeded source.
 * Type: int. Units: pixels. Scope: A1 synthetic scanner fixture.
 * Consumers: a1_mutate_fixture_photo. Rationale: 96 pixels matches the seed.
 */
const A1_MUTATE_IMAGE_WIDTH = 96;

/**
 * Keep mutation image height comparable to the original seeded source.
 * Type: int. Units: pixels. Scope: A1 synthetic scanner fixture.
 * Consumers: a1_mutate_fixture_photo. Rationale: 64 pixels matches the seed.
 */
const A1_MUTATE_IMAGE_HEIGHT = 64;

/**
 * Build one deliberately over-budget original, not a server thumbnail.
 * Type: int. Units: pixels. Scope: one disposable A1 source mutation.
 * Consumers: a1_mutate_fixture_photo. Rationale: noisy 768px source stays
 * above the 128 KiB catalogue hashing budget in the fallback-original lane.
 */
const A1_MUTATE_LARGE_WIDTH = 768;
/**
 * Bound the test's large source raster to one small disposable image.
 * Type: int. Units: pixels. Scope: one disposable A1 source mutation.
 * Consumers: a1_mutate_fixture_photo. Rationale: 512 rows exceed the hashing
 * budget while remaining inexpensive for the owned PHP scanner.
 */
const A1_MUTATE_LARGE_HEIGHT = 512;
/**
 * Avoid JPEG compression hiding the intended no-ETag fallback case.
 * Type: int. Units: JPEG quality percent. Scope: one A1 source mutation.
 * Consumers: a1_mutate_fixture_photo. Rationale: 100 retains noisy pixels.
 */
const A1_MUTATE_LARGE_JPEG_QUALITY = 100;
/**
 * Require the original to exceed the existing metadata digest budget.
 * Type: int. Units: bytes. Scope: one A1 fixture source validation.
 * Consumers: a1_mutate_fixture_photo. Rationale: 128 KiB is the server's
 * existing per-descriptor catalogue hashing cap, not a new service limit.
 */
const A1_MUTATE_LARGE_MIN_BYTES = 128 * 1024;

/**
 * Perform a real owned filesystem and database mutation.
 *
 * @param string $directory Verified disposable PHP gallery installation.
 * @param string $nonce Private gallery-workflow fixture ownership token.
 * @param string $operation One fixed small/large replace, add or remove action.
 * @return int Confirmed changed source or deleted database row count.
 * @throws RuntimeException When the scanner/deletion did not commit.
 */
function a1_mutate_fixture_photo(
    string $directory, string $nonce, string $operation,
): int {
    $root = \GalleryWorkflow\validateFixture($directory, $nonce);
    require_once $root . '/app/bootstrap_full.php';
    $seed = json_decode(
        (string) file_get_contents($root . '/seed.json'),
        true, flags: JSON_THROW_ON_ERROR,
    );
    $galleryId = (int) ($seed['root_id'] ?? 0);
    if ($galleryId <= 0) {
        throw new RuntimeException('Missing owned gallery identity.');
    }
    $query = \Gallery\Core\db()->prepare(
        'SELECT folder_path FROM galleries WHERE id = ?',
    );
    $query->execute([$galleryId]);
    $folder = $query->fetchColumn();
    if (!is_string($folder) || $folder === '') {
        throw new RuntimeException('Missing owned gallery folder.');
    }
    $target = $root . '/galleries/' . $folder;
    if (!is_dir($target)) {
        throw new RuntimeException('Missing owned gallery source directory.');
    }
    if ($operation === 'remove') {
        $query = \Gallery\Core\db()->prepare(
            'SELECT id FROM images WHERE gallery_id = ? AND filename = ?',
        );
        $query->execute([$galleryId, 'a1-photo-119.jpg']);
        $photoId = (int) $query->fetchColumn();
        if ($photoId <= 0) {
            throw new RuntimeException('Missing exact deletion target.');
        }
        $result = \Gallery\Services\delete_gallery_images(
            $galleryId, [$photoId],
        );
        if ((int) ($result['deleted'] ?? 0) !== 1) {
            throw new RuntimeException('Admin deletion did not commit one row.');
        }
        return 1;
    }
    if (!in_array($operation, ['replace', 'replace_large', 'add'], true)) {
        throw new RuntimeException('Unapproved mutation action.');
    }
    $large = $operation === 'replace_large';
    $replace = $operation === 'replace' || $large;
    $name = $replace ? 'a1-photo-000.jpg' : 'a1-photo-120.jpg';
    $path = $target . '/' . $name;
    if (($replace && !is_file($path))
        || ($operation === 'add' && file_exists($path))) {
        throw new RuntimeException('Mutation source precondition failed.');
    }
    $image = imagecreatetruecolor(
        $large ? A1_MUTATE_LARGE_WIDTH : A1_MUTATE_IMAGE_WIDTH,
        $large ? A1_MUTATE_LARGE_HEIGHT : A1_MUTATE_IMAGE_HEIGHT,
    );
    if (!$image instanceof GdImage) {
        throw new RuntimeException('Mutation raster creation failed.');
    }
    if ($large) {
        // Deterministic noisy raster ensures the original cannot masquerade
        // as a small catalogue-proven thumbnail after the second real scan.
        $noise = 1234567;
        for ($y = 0; $y < A1_MUTATE_LARGE_HEIGHT; $y++) {
            for ($x = 0; $x < A1_MUTATE_LARGE_WIDTH; $x++) {
                $noise = ($noise * 1103515245 + 12345) & 0x7fffffff;
                imagesetpixel($image, $x, $y, $noise & 0xffffff);
            }
        }
    } else {
        $background = imagecolorallocate(
            $image, $operation === 'replace' ? 234 : 17, 51, 133,
        );
        imagefill($image, 0, 0, $background);
        $foreground = imagecolorallocate($image, 249, 204, 46);
        for ($offset = 0; $offset < A1_MUTATE_IMAGE_WIDTH; $offset += 8) {
            imageline(
                $image, $offset, 0, A1_MUTATE_IMAGE_WIDTH - 1 - $offset,
                A1_MUTATE_IMAGE_HEIGHT - 1, $foreground,
            );
        }
    }
    $written = imagejpeg(
        $image, $path, $large ? A1_MUTATE_LARGE_JPEG_QUALITY : 80,
    );
    unset($image);
    if (!$written) {
        throw new RuntimeException('Mutation raster persistence failed.');
    }
    if ($large && (int) filesize($path) <= A1_MUTATE_LARGE_MIN_BYTES) {
        throw new RuntimeException('Large fixture failed to exceed hash budget.');
    }
    if ($replace) {
        // Distinct second-level mtimes exercise the real scanner's source
        // mutation detection even when two benchmark phases share a second.
        touch($path, time() + ($large ? 10 : 5));
    }
    clearstatcache(true, $path);
    $changed = \Gallery\Services\scan_gallery_images($galleryId);
    if ($changed < 1) {
        throw new RuntimeException('Actual scanner ignored the changed source.');
    }
    return $changed;
}

try {
    if (PHP_SAPI !== 'cli' || count($argv) !== 4
        || getenv('GALLERY_WORKFLOW_ENABLE') !== 'disposable-only') {
        throw new RuntimeException('Owned A1 mutation fixture is required.');
    }
    $changed = a1_mutate_fixture_photo($argv[1], $argv[2], $argv[3]);
    echo 'PASS A1 actual photo mutation; affected=' . $changed . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL A1 actual photo mutation ('
        . basename($error->getFile()) . ':' . $error->getLine() . ")\n");
    exit(1);
}
