<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tools/perf/issue_362_a1_seed.php
 * Module Type: Disposable Benchmark Seed
 * Purpose: Generate a bounded, synthetic physical album for the A1 HTTP fixture.
 * Responsibilities:
 *   - Accept only the existing owned gallery-workflow fixture.
 *   - Write test JPEGs into its isolated gallery directory.
 *   - Register files through the production gallery image scanner.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/tests/support/gallery_workflow_safety.php';

/**
 * Limit the count of synthetic photo files in one disposable album.
 * Type: int. Units: images. Scope: issue #362 isolated HTTP test.
 * Consumers: photo creation loop. Rationale: 120 distinct images meet A1 T1.
 */
const A1_SEED_PHOTO_COUNT = 120;

/**
 * Limit synthetic image width for fast fixture-only generation.
 * Type: int. Units: pixels. Scope: issue #362 isolated HTTP test.
 * Consumers: GD allocation. Rationale: 96 columns provide bounded JPEG reads.
 */
const A1_SEED_IMAGE_WIDTH = 96;

/**
 * Limit synthetic image height for fast fixture-only generation.
 * Type: int. Units: pixels. Scope: issue #362 isolated HTTP test.
 * Consumers: GD allocation. Rationale: 64 rows provide bounded JPEG reads.
 */
const A1_SEED_IMAGE_HEIGHT = 64;

/**
 * Populate exactly one owned gallery using real application file registration.
 *
 * @param string $directory Verified disposable application copy.
 * @param string $token Its ownership nonce, never written to an artifact.
 * @return int Count of newly written synthetic JPEG originals.
 */
function a1_seed_photos(string $directory, string $token): int
{
    $root = \GalleryWorkflow\validateFixture($directory, $token);
    require_once $root . '/app/bootstrap_full.php';
    $record = json_decode(
        (string) file_get_contents($root . '/seed.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $galleryId = (int) ($record['root_id'] ?? 0);
    $statement = \Gallery\Core\db()->prepare(
        'SELECT folder_path FROM galleries WHERE id = ?',
    );
    $statement->execute([$galleryId]);
    $folder = $statement->fetchColumn();
    if (!is_string($folder) || $folder === '') {
        throw new RuntimeException('Synthetic gallery is unavailable.');
    }
    $target = $root . '/galleries/' . $folder;
    if (!is_dir($target)) {
        throw new RuntimeException('Synthetic gallery directory is unavailable.');
    }
    for ($index = 0; $index < A1_SEED_PHOTO_COUNT; ++$index) {
        $image = imagecreatetruecolor(
            A1_SEED_IMAGE_WIDTH, A1_SEED_IMAGE_HEIGHT,
        );
        if (!$image instanceof GdImage) {
            throw new RuntimeException('Could not generate a synthetic JPEG.');
        }
        $color = imagecolorallocate(
            $image, ($index * 19) % 256, ($index * 31) % 256,
            ($index * 47) % 256,
        );
        imagefill($image, 0, 0, $color);
        $filename = sprintf('a1-photo-%03d.jpg', $index);
        $ok = imagejpeg($image, $target . '/' . $filename, 82);
        unset($image);
        if (!$ok) {
            throw new RuntimeException('Could not write a synthetic JPEG.');
        }
    }
    \Gallery\Services\scan_gallery_images($galleryId);
    $count = \Gallery\Core\db()->prepare(
        "SELECT COUNT(*) FROM images WHERE gallery_id = ? AND "
        . "relative_path NOT LIKE '%/%'",
    );
    $count->execute([$galleryId]);
    if ((int) $count->fetchColumn() < A1_SEED_PHOTO_COUNT) {
        throw new RuntimeException('Scanner did not register 120 synthetic images.');
    }
    return A1_SEED_PHOTO_COUNT;
}

try {
    if (PHP_SAPI !== 'cli' || count($argv) !== 3) {
        throw new RuntimeException('Owned fixture CLI arguments required.');
    }
    $created = a1_seed_photos($argv[1], $argv[2]);
    echo 'PASS gallery workflow A1 seeded ' . $created . " images\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL gallery workflow A1 synthetic photo seed (' .
        basename($error->getFile()) . ':' . $error->getLine() . ")\n");
    exit(1);
}
