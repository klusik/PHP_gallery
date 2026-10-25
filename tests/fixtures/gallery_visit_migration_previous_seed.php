<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/fixtures/gallery_visit_migration_previous_seed.php
 * Module Type: Upgrade Fixture
 * Purpose: Represent the seed symbol already loaded by a previous visitor release.
 * Responsibilities: Reject reuse of the obsolete runtime seeder during upgrade.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Models;

/**
 * Refuse the old seed implementation already present in a previous PHP runtime.
 * @param \PDO $pdo Unused explicit fixture connection.
 * @return void Never returns; the migration must use its independent owner.
 * @throws \RuntimeException Whenever the obsolete runtime seed is invoked.
 */
function gallery_visit_content_model_seed(\PDO $pdo): void
{
    throw new \RuntimeException('Migration reused an obsolete runtime seed.');
}
