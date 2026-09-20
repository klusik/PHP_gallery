<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_edit_writer_guards_test.php
 * Module Type: Regression Test
 * Purpose: Verify the complete gallery filesystem-writer guard inventory without a database.
 * Responsibilities:
 *   - Require ownership before use-case reads and target filesystem effects
 *   - Exercise the actual wrapper source through returns, failures and lock refusal
 *   - Keep split-module readers and global-before-pair lock ordering explicit
 * Author: Rudolf Klusal
 * Contact: https://github.com/klusik
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once __DIR__ . '/support/module_source.php';
require_once __DIR__ . '/support/gallery_edit_writer_source.php';
require_once __DIR__ . '/support/gallery_edit_writer_inventory.php';


/**
 * Exercise the exact public wrapper with local seams instead of production persistence.
 *
 * Only the three delegated calls are replaced. The real signature, try/finally,
 * return statement and exception propagation execute unchanged.
 *
 * @param string $name Public use-case name.
 * @param array{declaration:string,body:string,doc:string} $function Source under test.
 * @param string $returnType Declared result type.
 * @return void
 */
function gallery_writer_exercise(string $name, array $function, string $returnType): void
{
    foreach (['return', 'operation_failure', 'acquire_failure', 'release_failure'] as $mode) {
        $events = [];
        $failure = new RuntimeException('fixture-' . $mode);
        $value = match ($returnType) {
            'array', '?array' => ['fixture_result' => true],
            'int' => 7,
            'string' => 'fixture-result',
            'void' => null,
            default => throw new RuntimeException('Uncovered wrapper return type: ' . $name),
        };
        /**
         * Record acquisition and optionally refuse before the delegated writer runs.
         * @return string Fixture lease; acquisition-failure cases throw the exact injected exception.
         * @author Rudolf Klusal
         */
        $acquire = static function () use (&$events, $mode, $failure): string {
            $events[] = 'begin';
            if ($mode === 'acquire_failure') {
                throw $failure;
            }
            return 'fixture-lease';
        };
        /**
         * Stand in for the owned writer body with an immediate result or injected failure.
         * @param int|string|bool|array<array-key,mixed>|null ...$arguments Inert type-compatible inputs generated from the public wrapper signature.
         * @return int|string|array{fixture_result:bool}|null Sentinel matching the wrapper's native return type.
         * @author Rudolf Klusal
         */
        $operation = static function (...$arguments) use (&$events, $mode, $failure, $value): mixed {
            $events[] = 'operation';
            if ($mode === 'operation_failure') {
                throw $failure;
            }
            return $value;
        };
        /**
         * Verify release uses the acquired lease and optionally inject a cleanup failure.
         * @param string $lease Fixture lease expected from this exact acquisition.
         * @return void Records cleanup, then throws only in the release-failure case.
         * @author Rudolf Klusal
         */
        $release = static function (string $lease) use (&$events, $mode, $failure): void {
            gallery_writer_check($lease === 'fixture-lease', 'Released a different lease.');
            $events[] = 'end';
            if ($mode === 'release_failure') {
                throw $failure;
            }
        };
        $declaration = str_replace('function ' . $name, 'static function', $function['declaration']);
        $declaration = preg_replace('/\)\s*:/', ') use ($acquire, $operation, $release):', $declaration, 1);
        $body = str_replace(
            ['gallery_edit_writer_begin()', $name . '_owned(', 'gallery_edit_writer_end('],
            ['$acquire()', '$operation(', '$release('],
            $function['body']
        );
        $callable = eval('return ' . $declaration . ' ' . $body . ';');
        gallery_writer_check($callable instanceof Closure, 'Wrapper did not compile: ' . $name);
        $arguments = gallery_edit_writer_fixture_arguments(new ReflectionFunction($callable));
        try {
            $actual = $callable(...$arguments);
            gallery_writer_check($mode === 'return' && $actual === $value, 'Changed wrapper result: ' . $name);
        } catch (RuntimeException $exception) {
            gallery_writer_check($mode !== 'return' && $exception === $failure, 'Changed wrapper failure: ' . $name);
        }
        $expectedEvents = $mode === 'acquire_failure' ? ['begin'] : ['begin', 'operation', 'end'];
        gallery_writer_check($events === $expectedEvents, 'Ownership lifetime failure: ' . $name . '/' . $mode);
    }
}

// Every ordinary outer writer in the documented inventory, including the other
// implementation owners. Entry-point readers include their ordered part files.
$inventory = gallery_edit_writer_fixture_inventory();
$root = dirname(__DIR__) . '/app/services/';
$allFunctions = [];
$checked = 0;
foreach ($inventory as $module => $names) {
    $functions = gallery_writer_functions(module_source($root . $module));
    $allFunctions += $functions;
    foreach ($names as $name) {
        gallery_writer_check(isset($functions[$name], $functions[$name . '_owned']), 'Missing guarded writer: ' . $name);
        $function = $functions[$name];
        preg_match('/:\s*(\??\w+)$/', $function['declaration'], $returnMatch);
        $returnType = $returnMatch[1] ?? '';
        $arguments = [];
        foreach (token_get_all('<?php ' . $function['declaration']) as $token) {
            if (is_array($token) && $token[0] === T_VARIABLE) {
                $arguments[] = $token[1];
            }
        }
        $expected = '{ $writerLock = gallery_edit_writer_begin(); try { '
            . ($returnType === 'void' ? '' : 'return ') . $name . '_owned(' . implode(', ', $arguments)
            . '); } finally { gallery_edit_writer_end($writerLock); } }';
        gallery_writer_check(gallery_writer_compact($function['body']) === gallery_writer_compact($expected),
            'Writer must begin before reads, delegate once and release in finally: ' . $name);
        $owned = $functions[$name . '_owned'];
        gallery_writer_check(
            str_replace($name . '_owned', $name, $owned['declaration']) === $function['declaration'],
            'Owned implementation changed the public signature: ' . $name
        );
        gallery_writer_check(str_contains($function['doc'], '@return') && str_contains($owned['doc'], '@return'),
            'Writer result documentation missing: ' . $name);
        foreach ($arguments as $argument) {
            gallery_writer_check(str_contains($owned['doc'], $argument), 'Owned parameter documentation missing: ' . $name);
        }
        gallery_writer_exercise($name, $function, $returnType);
        $checked++;
    }
}

// The journal owners additionally hold per-gallery locks. Read-only schema and
// immutable journal-ID checks may precede global ownership, but pair acquisition,
// recovery and every target effect must remain inside the global try/finally.
foreach (['gallery_mutations.php' => 'move_gallery_images', 'gallery_image_move_journal.php' => 'gallery_image_move_recover'] as $module => $name) {
    $function = gallery_writer_functions(module_source($root . $module))[$name] ?? null;
    gallery_writer_check(is_array($function), 'Missing journal boundary: ' . $name);
    $body = gallery_writer_compact($function['body']);
    $begin = strpos($body, '$writerLock=gallery_edit_writer_begin();');
    $pair = strpos($body, 'gallery_image_move_model_lock(');
    gallery_writer_check($begin !== false && $pair !== false && $begin < $pair, 'Pair lock precedes global ownership: ' . $name);
    gallery_writer_check(str_contains($body, '$locks=[];try{$locks=') && str_ends_with($body, 'finally{gallery_edit_writer_end($writerLock);}}}'),
        'Journal boundary can leak global ownership: ' . $name);
}

foreach (['scan_gallery_images_owned', 'scan_gallery_selected_images_owned', 'scan_gallery_selected_uploaded_images_owned', 'browser_upload_store_prepared_zip_batch_owned'] as $name) {
    gallery_writer_check(str_contains($allFunctions[$name]['body'], 'find_gallery($galleryId, true)'), 'Cached pre-lock gallery read: ' . $name);
}
gallery_writer_check(str_contains($allFunctions['mobile_webdav_store_put_owned']['body'], "find_gallery((int) \$token['gallery_id'], true)"), 'WebDAV reused a pre-lock row.');
gallery_writer_check(str_contains($allFunctions['gallery_trash_reconcile_entry_owned']['body'], '$currentEntry = gallery_trash_entry('), 'Trash recovery trusted a stale lifecycle row.');
gallery_writer_check(str_contains($allFunctions['save_gallery_thumbnail_bounds_owned']['body'], "find_gallery((int) (\$gallery['id'] ?? 0), true)"), 'Recursive bounds trusted an old folder path.');
gallery_writer_check(str_contains($allFunctions['gallery_editor_set_cover_image_owned']['body'], 'find_image($coverImageId, true)')
    && !str_contains($allFunctions['gallery_editor_set_cover_image_owned']['body'], '?: $fallbackGallery'),
    'Cover persistence trusted stale ownership or a caller snapshot.');
gallery_writer_check(str_contains($allFunctions['gallery_migration_sync_received_assets_owned']['body'], 'gallery_migration_load_job('), 'Migration recovery trusted caller job progress.');
gallery_writer_check(str_contains($allFunctions['picture_manager_owned_images_for_selection']['body'], 'find_image((int) $imageId, true)'), 'Copy validation reused a pre-lock image row.');
gallery_writer_check(str_contains($allFunctions['gallery_migration_install_thumbnail']['body'], 'find_image($imageId, true)'), 'Migration thumbnail installation reused a pre-lock image row.');
gallery_writer_check(str_starts_with(gallery_writer_compact($allFunctions['media_renamer_execute_plan_owned']['body']),
    '{$plan=media_renamer_refresh_execution_plan($plan);'), 'Rename execution used a caller plan before revalidation.');

echo 'PASS gallery writer guards: ' . $checked . " wrappers, four lifetime paths each, journal lock ordering and fresh-row reads\n";
