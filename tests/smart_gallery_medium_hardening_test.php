<?php

/**
 * Regression contracts for Smart Gallery medium-priority release hardening.
 *
 * These checks protect hierarchy mutation preflight/cache invalidation and the
 * bounded, serialized, atomically published Smart Gallery ZIP build pipeline.
 */

declare(strict_types=1);

/** Fail this standalone test with one concise contract message. */
function smart_gallery_medium_hardening_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$mutations = (string) file_get_contents($root . '/app/services/gallery_mutations.php');
$publicPaths = (string) file_get_contents($root . '/app/services/public_paths.php');
$reorder = (string) file_get_contents($root . '/app/controllers/admin_galleries_reorder.php');
$downloads = (string) file_get_contents($root . '/app/services/downloads.php');
$downloadController = (string) file_get_contents($root . '/app/controllers/downloads.php');
$config = (string) file_get_contents($root . '/config.example.php');

$syncStart = strpos($mutations, 'function sync_gallery_parent_ids');
$syncEnd = strpos($mutations, '/**', $syncStart === false ? 0 : $syncStart + 1);
$syncSource = $syncStart !== false
    ? substr($mutations, $syncStart, $syncEnd !== false ? $syncEnd - $syncStart : null)
    : '';
smart_gallery_medium_hardening_assert(
    $syncSource !== ''
    && str_contains($syncSource, '$desiredParentById = [];')
    && str_contains($syncSource, 'smart_gallery_validate_gallery_parent_map($desiredParentById);')
    && strpos($syncSource, 'smart_gallery_validate_gallery_parent_map($desiredParentById);') < strpos($syncSource, "UPDATE galleries SET parent_id"),
    'Filesystem parent synchronization validates the complete proposed parent map before its first parent_id UPDATE.'
);
smart_gallery_medium_hardening_assert(
    str_contains($syncSource, 'smart_gallery_graph_cache_clear();')
    && str_contains($syncSource, '$hierarchyChanged'),
    'Filesystem parent synchronization clears the request-local Smart Gallery graph after hierarchy writes.'
);

$repairStart = strpos($publicPaths, 'function repair_gallery_parent_ids_from_folder_paths');
$repairEnd = strpos($publicPaths, '/**', $repairStart === false ? 0 : $repairStart + 1);
$repairSource = $repairStart !== false
    ? substr($publicPaths, $repairStart, $repairEnd !== false ? $repairEnd - $repairStart : null)
    : '';
smart_gallery_medium_hardening_assert(
    $repairSource !== ''
    && str_contains($repairSource, 'smart_gallery_validate_gallery_parent_map($assignments);')
    && strpos($repairSource, 'smart_gallery_validate_gallery_parent_map($assignments);') < strpos($repairSource, "UPDATE galleries SET parent_id")
    && str_contains($repairSource, 'smart_gallery_graph_cache_clear();'),
    'Public-path parent repair validates before writes and invalidates the Smart Gallery graph after a committed hierarchy change.'
);

$moveStart = strpos($mutations, 'function move_gallery_folder_to_parent');
$moveEnd = strpos($mutations, '/**', $moveStart === false ? 0 : $moveStart + 1);
$moveSource = $moveStart !== false
    ? substr($mutations, $moveStart, $moveEnd !== false ? $moveEnd - $moveStart : null)
    : '';
smart_gallery_medium_hardening_assert(
    str_contains($moveSource, 'bool $smartGalleryGraphPrevalidated = false')
    && str_contains($moveSource, '!$smartGalleryGraphPrevalidated')
    && str_contains($moveSource, 'smart_gallery_graph_cache_clear();'),
    'Physical gallery moves have an explicit prevalidated-batch path while still invalidating cached graph state after each committed hierarchy mutation.'
);
smart_gallery_medium_hardening_assert(
    str_contains($reorder, 'smart_gallery_validate_gallery_parent_map($submittedParentById);')
    && str_contains($reorder, 'move_gallery_folder_to_parent($galleryId, $parentId > 0 ? $parentId : null, null, true);')
    && str_contains($reorder, 'sync_gallery_parent_ids(true);')
    && !str_contains($reorder, 'move_gallery_folder_to_parent($galleryId, $parentId > 0 ? $parentId : null);' . "\n            " . '$movedCount++;' . "\n            " . '$activeMoveDiagnostics = null;' . "\n            " . 'sync_gallery_parent_ids();'),
    'A fully preflighted drag-and-drop batch does not revalidate or synchronize against temporary intermediate parent maps.'
);

smart_gallery_medium_hardening_assert(
    str_contains($downloads, 'const SMART_GALLERY_ZIP_MAX_IMAGES = 5000;')
    && str_contains($downloads, 'const SMART_GALLERY_ZIP_DEFAULT_MAX_SOURCE_BYTES = 2147483648;')
    && str_contains($downloads, 'function smart_gallery_zip_max_source_bytes()')
    && str_contains($config, "'smart_gallery_zip_max_source_bytes' => 2 * 1024 * 1024 * 1024"),
    'Smart Gallery ZIP generation has separate file-count and configurable aggregate source-byte ceilings with a safe default for existing installs.'
);
smart_gallery_medium_hardening_assert(
    str_contains($downloads, 'if ($fileSize > $maxSourceBytes - $sourceBytes)')
    && str_contains($downloads, "'source_bytes_limit'")
    && str_contains($downloads, '$sourceBytes += $fileSize;'),
    'Aggregate source bytes are checked incrementally before accepting each original into the archive.'
);
smart_gallery_medium_hardening_assert(
    str_contains($downloads, 'fopen($lockPath, \'c\')')
    && str_contains($downloads, 'flock($lockHandle, LOCK_EX)')
    && str_contains($downloads, 'zip_cache_file_is_fresh($filePath)')
    && str_contains($downloads, ".partial-")
    && str_contains($downloads, 'rename($partialPath, $filePath)')
    && str_contains($downloads, 'flock($lockHandle, LOCK_UN)'),
    'Smart Gallery ZIP builds use an exclusive signature lock, recheck cache state, write a unique partial archive, and atomically publish the final path.'
);

$controllerStart = strpos($downloadController, 'function cms_download_smart_gallery');
$controllerEnd = strpos($downloadController, '/**', $controllerStart === false ? 0 : $controllerStart + 1);
$controllerSource = $controllerStart !== false
    ? substr($downloadController, $controllerStart, $controllerEnd !== false ? $controllerEnd - $controllerStart : null)
    : '';
smart_gallery_medium_hardening_assert(
    $controllerSource !== ''
    && str_contains($controllerSource, "'reason' => smart_gallery_zip_failure_reason(\$exception)")
    && str_contains($controllerSource, "'exception_class' => get_class(\$exception)")
    && !str_contains($controllerSource, '$exception->getMessage()'),
    'Persistent Smart Gallery download failures use stable reason/class diagnostics and never store raw exception messages.'
);

fwrite(STDOUT, "Smart Gallery medium-priority hardening tests passed.\n");
