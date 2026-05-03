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
    // $safeTable stores an intermediate value used by the surrounding gallery workflow.
    $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    // $safeColumn stores an intermediate value used by the surrounding gallery workflow.
    $safeColumn = str_replace(["\\", "'"], ['', "\\'"], $column);
    if ($safeTable === '' || $safeColumn === '') {
        return false;
    }

    // $stmt stores an intermediate value used by the surrounding gallery workflow.
    $stmt = db()->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
    return (bool) $stmt->fetch();
}
