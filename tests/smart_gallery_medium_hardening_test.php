<?php

/**
 * Project: PHP Gallery
 * Module Type: Regression Test
 * Purpose: Protect Smart Gallery hierarchy and archive hardening.
 * Responsibilities:
 *   - Verify mutation preflight, invalidation and serialized atomic ZIP publication.
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/smart_gallery_medium_hardening_test.php
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 */
/**
 * Regression contracts for Smart Gallery medium-priority release hardening.
 *
 * These checks protect hierarchy mutation preflight/cache invalidation and the
 * bounded, serialized, atomically published Smart Gallery ZIP build pipeline.
 */

declare(strict_types=1);

require_once __DIR__ . '/support/php_source.php';

require_once __DIR__ . '/support/module_source.php';

/**
 * Fail this standalone test with one concise contract message.
 *
 * @param bool $condition Whether the hardening contract holds.
 * @param string $message Failure diagnostic.
 * @return void Writes the diagnostic to stderr and exits on failure.
 */
function smart_gallery_medium_hardening_assert(
    bool $condition,
    string $message,
): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$mutations = module_source($root . '/app/services/gallery_mutations.php');
$mutationModel = (string) file_get_contents($root
    . '/app/models/gallery_mutations.php');
$publicPaths = (string) file_get_contents($root
    . '/app/services/public_paths.php');
$publicPathModel = (string) file_get_contents($root
    . '/app/models/public_paths.php');
$reorder = (string) file_get_contents($root
    . '/app/controllers/admin_galleries_reorder.php');
$downloads = (string) file_get_contents($root . '/app/services/downloads.php');
$downloadController = (string) file_get_contents($root
    . '/app/controllers/downloads.php');
$config = (string) file_get_contents($root . '/app/configuration_defaults.php');

$syncStart = strpos($mutations, 'function sync_gallery_parent_ids');
$syncEnd = strpos($mutations, '/**', $syncStart === false ? 0 : $syncStart + 1);
$syncSource = $syncStart !== false
    ? substr(
        $mutations,
        $syncStart,
        $syncEnd !== false ? $syncEnd - $syncStart : null,
    )
    : '';
smart_gallery_medium_hardening_assert(
    $syncSource !== ''
        && str_contains($syncSource, '$desiredParentById = [];')
        && php_source_contains(
            $syncSource,
            'smart_gallery_validate_gallery_parent_map($desiredParentById);',
        )
        && php_source_contains(
            $syncSource,
            'gallery_mutation_model_sync_parent_map($galleries, $desiredParentById, now_sql())',
        )
        && strpos(
            php_source_contract_tokens('<?php ' . $syncSource),
            php_source_contract_tokens(
                '<?php smart_gallery_validate_gallery_parent_map($desiredParentById);',
            ),
        ) < strpos(
            php_source_contract_tokens('<?php ' . $syncSource),
            php_source_contract_tokens(
                '<?php gallery_mutation_model_sync_parent_map($galleries, $desiredParentById, now_sql())',
            ),
        )
        && php_source_contains($mutationModel, 'UPDATE galleries SET parent_id = NULL')
        && php_source_contains(
            $mutationModel,
            'UPDATE galleries SET parent_id = ?, updated_at = ?',
        ),
    'Filesystem parent synchronization validates the complete proposed parent map before model-owned parent_id persistence.',
);
smart_gallery_medium_hardening_assert(
    php_source_contains($syncSource, 'smart_gallery_graph_cache_clear();')
        && str_contains($syncSource, '$hierarchyChanged'),
    'Filesystem parent synchronization clears the request-local Smart Gallery graph after hierarchy writes.',
);

$repairStart = strpos(
    $publicPaths,
    'function repair_gallery_parent_ids_from_folder_paths',
);
$repairEnd = strpos(
    $publicPaths,
    '/**',
    $repairStart === false ? 0 : $repairStart + 1,
);
$repairSource = $repairStart !== false
    ? substr(
        $publicPaths,
        $repairStart,
        $repairEnd !== false ? $repairEnd - $repairStart : null,
    )
    : '';
$repairTokens = php_source_contract_tokens('<?php ' . $repairSource);
smart_gallery_medium_hardening_assert(
    $repairSource !== ''
        && php_source_contains(
            $repairSource,
            'smart_gallery_validate_gallery_parent_map($assignments);',
        )
        && php_source_contains(
            $repairSource,
            'public_path_model_apply_parent_assignments($rows, $assignments, $pdo)',
        )
        && strpos(
            $repairTokens,
            php_source_contract_tokens(
                '<?php smart_gallery_validate_gallery_parent_map($assignments);',
            ),
        ) < strpos(
            $repairTokens,
            php_source_contract_tokens(
                '<?php public_path_model_apply_parent_assignments($rows, $assignments, $pdo)',
            ),
        )
        && php_source_contains($repairSource, 'smart_gallery_graph_cache_clear();')
        && php_source_contains(
            $publicPathModel,
            'UPDATE galleries SET parent_id = ?, edit_revision = edit_revision + 1 WHERE id = ?',
        ),
    'Public-path parent repair validates before model-owned writes and invalidates the Smart Gallery graph after a committed hierarchy change.',
);

$moveWrapperStart = strpos(
    $mutations,
    'function move_gallery_folder_to_parent(',
);
$moveWrapperEnd = strpos(
    $mutations,
    '/**',
    $moveWrapperStart === false ? 0 : $moveWrapperStart + 1,
);
$moveWrapper = $moveWrapperStart !== false
    ? substr(
        $mutations,
        $moveWrapperStart,
        $moveWrapperEnd !== false ? $moveWrapperEnd - $moveWrapperStart : null,
    )
    : '';
smart_gallery_medium_hardening_assert(
    php_source_contains($moveWrapper, 'gallery_edit_writer_begin()')
        && php_source_contains(
            $moveWrapper,
            'return move_gallery_folder_to_parent_owned($galleryId, $parentId, $folderName, $smartGalleryGraphPrevalidated);',
        )
        && php_source_contains($moveWrapper, 'finally')
        && php_source_contains($moveWrapper, 'gallery_edit_writer_end($writerLock);'),
    'The physical-move boundary retains writer ownership and forwards the explicit prevalidated-batch decision unchanged.',
);
$moveStart = strpos(
    $mutations,
    'function move_gallery_folder_to_parent_owned(',
);
$moveEnd = strpos($mutations, '/**', $moveStart === false ? 0 : $moveStart + 1);
$moveSource = $moveStart !== false
    ? substr(
        $mutations,
        $moveStart,
        $moveEnd !== false ? $moveEnd - $moveStart : null,
    )
    : '';
smart_gallery_medium_hardening_assert(
    php_source_contains($moveSource, 'bool $smartGalleryGraphPrevalidated = false')
        && php_source_contains($moveSource, '!$smartGalleryGraphPrevalidated')
        && php_source_contains($moveSource, 'smart_gallery_graph_cache_clear();'),
    'Physical gallery moves have an explicit prevalidated-batch path while still invalidating cached graph state after each committed hierarchy mutation.',
);
smart_gallery_medium_hardening_assert(
    php_source_contains(
        $reorder,
        'smart_gallery_validate_gallery_parent_map($submittedParentById);',
    )
        && php_source_contains(
            $reorder,
            'move_gallery_folder_to_parent($galleryId, $parentId > 0 ? $parentId : null, null, true);',
        )
        && php_source_contains($reorder, 'sync_gallery_parent_ids(true);')
        && !php_source_contains(
            $reorder,
            'move_gallery_folder_to_parent($galleryId, $parentId > 0 ? $parentId : null);'
                . '$movedCount++;'
                . '$activeMoveDiagnostics = null;'
                . 'sync_gallery_parent_ids();',
        ),
    'A fully preflighted drag-and-drop batch does not revalidate or synchronize against temporary intermediate parent maps.',
);

smart_gallery_medium_hardening_assert(
    php_source_contains(
        $downloads,
        "cms_runtime_limit('download.smart_gallery_zip_max_images')",
    )
        && php_source_contains(
            $downloads,
            'function smart_gallery_zip_max_source_bytes()',
        )
        && php_source_contains(
            $downloads,
            "cms_runtime_limit('download.smart_gallery_zip_max_source_bytes')",
        )
        && str_contains(
            $config,
            "'download.smart_gallery_zip_max_images' => 5000",
        )
        && str_contains(
            $config,
            "'download.smart_gallery_zip_max_source_bytes' => 2 * 1024 * 1024 * 1024",
        ),
    'Smart Gallery ZIP generation has separate file-count and configurable aggregate source-byte ceilings with a safe default for existing installs.',
);
smart_gallery_medium_hardening_assert(
    php_source_contains(
        $downloads,
        'if ($fileSize > ($maxSourceBytes - $sourceBytes))',
    )
        && php_source_contains($downloads, "'source_bytes_limit'")
        && php_source_contains($downloads, '$sourceBytes += $fileSize;'),
    'Aggregate source bytes are checked incrementally before accepting each original into the archive.',
);
smart_gallery_medium_hardening_assert(
    php_source_contains($downloads, "@fopen(\$lockPath, 'c')")
        && php_source_contains($downloads, 'flock($lockHandle, LOCK_EX | LOCK_NB)')
        && php_source_contains($downloads, 'zip_cache_file_is_fresh($filePath)')
        && str_contains($downloads, '.partial-')
        && php_source_contains($downloads, '@rename($partialPath, $filePath)')
        && php_source_contains($downloads, '@flock($lockHandle, LOCK_UN)'),
    'Smart Gallery ZIP builds use an exclusive signature lock, recheck cache state, write a unique partial archive, and atomically publish the final path.',
);

$controllerStart = strpos(
    $downloadController,
    'function cms_download_smart_gallery(): void',
);
$controllerEnd = strpos(
    $downloadController,
    '/**',
    $controllerStart === false ? 0 : $controllerStart + 1,
);
$controllerSource = $controllerStart !== false
    ? substr(
        $downloadController,
        $controllerStart,
        $controllerEnd !== false ? $controllerEnd - $controllerStart : null,
    )
    : '';
smart_gallery_medium_hardening_assert(
    $controllerSource !== ''
        && php_source_contains(
            $controllerSource,
            'smart_gallery_zip_failure_reason($exception)',
        )
        && php_source_contains(
            $controllerSource,
            'cms_download_failure_request_context(\'download_smart_gallery\', $failureStage, $reason, $exception)',
        )
        && php_source_contains(
            $downloadController,
            '\'exception_class\' => get_class($exception)',
        )
        && php_source_contains(
            $downloadController,
            '\'exception_message\' => cms_download_failure_exception_message($exception)',
        )
        && !php_source_contains(
            $controllerSource,
            '\'exception_message\' => $exception->getMessage()',
        ),
    'Persistent Smart Gallery download failures use stable reason/class diagnostics and never store raw exception messages.',
);

fwrite(STDOUT, "Smart Gallery medium-priority hardening tests passed.\n");
