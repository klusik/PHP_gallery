<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/cache_lifecycle/storage.php
 * Module Type: Service Part
 * Purpose: Serialize lifecycle worker operations and define compact traversal frames.
 * Responsibilities: Retain nonblocking worker ownership and bounded restart state; shared paths, leases and passive evidence belong to cache_lifecycle_storage.php.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Services;

/**
 * Serialize one lifecycle operation with a nonblocking kernel lock.
 * @param callable():array<string,mixed> $operation Operation returning a validated lifecycle state/summary.
 * @return array<string,mixed> Operation result, or ok:false/busy:true when another worker owns it.
 */
function cache_lifecycle_with_lock(callable $operation): array
{
    $directory = cache_lifecycle_control_directory();
    cache_lifecycle_assert_path($directory . '/worker.lock');
    $handle = @fopen($directory . '/worker.lock', 'c');
    if ($handle === false) throw new \RuntimeException('Cache lifecycle lock is unavailable.');
    try {
        if (!flock($handle, LOCK_EX | LOCK_NB)) return ['ok'=>false,'busy'=>true,'reason'=>'cache_lifecycle_busy'];
        return $operation();
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/**
 * Define an initially empty bounded directory traversal frame.
 * @param string $relative Cache-relative directory, empty for the cache root.
 * @return array{path:string,offset:int,catalog:string,catalog_ok:bool,catalog_attempts:int,bytes:int,files:int,dirs:int,newest:int,pinned:bool,unsafe:bool} Compact counters and durable journal offset; directory names are streamed separately.
 */
function cache_lifecycle_frame(string $relative): array
{
    return ['path'=>$relative,'offset'=>0,'catalog'=>'','catalog_ok'=>false,'catalog_attempts'=>0,'bytes'=>0,'files'=>0,'dirs'=>0,'newest'=>0,'pinned'=>false,'unsafe'=>false];
}
