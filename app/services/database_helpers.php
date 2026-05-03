<?php

declare(strict_types=1);

/**
 * Database schema helper service.
 *
 * Contains tiny schema-inspection helpers used by optional migrations and
 * feature gates. These helpers stay generic and must not contain feature-specific
 * rendering or mutation logic.
 */

/**
 * Check whether one database table contains a column.
 */
function db_column_exists(string $table, string $column): bool
{
    $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $safeColumn = str_replace(["\\", "'"], ['', "\\'"], $column);
    if ($safeTable === '' || $safeColumn === '') {
        return false;
    }

    $stmt = db()->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
    return (bool) $stmt->fetch();
}
