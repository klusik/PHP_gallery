<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/fixtures/gallery_visit_migration_legacy_model.php
 * Module Type: Upgrade Fixture
 * Purpose: Represent a gallery model loaded before visitor content existed.
 * Responsibilities: Refuse accidental use of the old runtime database adapter.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Models;

/**
 * Refuse the old runtime adapter instead of silently using application storage.
 * @param list<int> $galleryIds Unused physical source identities.
 * @param string $kind Unused attachment kind.
 * @return array{} Never returns; the migration must own its explicit PDO reads.
 * @throws \RuntimeException Whenever migration code reaches this old adapter.
 */
function gallery_model_attachment_usage_rows(array $galleryIds, string $kind): array
{
    throw new \RuntimeException('Migration reached a preloaded runtime adapter.');
}
