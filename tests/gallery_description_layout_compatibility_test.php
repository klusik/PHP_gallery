<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_description_layout_compatibility_test.php
 * Module Type: Standalone Regression Test
 * Purpose: Verify orientation compatibility, inherited values and interrupted-migration replay.
 * Responsibilities:
 *   - Exercise domain conversion without a live database or gallery directory.
 *   - Verify isolated sidecar preservation and database rollback/checkpoint orchestration.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Models {
    /** Return isolated migration inputs. @param \PDO $pdo Fixture connection. @return array<string,mixed> Fixture persistence state. */
    function gallery_layout_migration_model_state(\PDO $pdo): array { return $GLOBALS['layout_state']; }
    /** Start a fixture transaction. @param \PDO $pdo Fixture connection. @return void Captures state for rollback. */
    function gallery_layout_migration_model_begin(\PDO $pdo): void { $GLOBALS['layout_before'] = $GLOBALS['layout_state']; }
    /** Capture migration writes. @param \PDO $pdo Fixture connection. @param array<string,string> $settings Prepared settings. @param array<int,array{id:int,json:string}> $smart Smart documents. @param array<int,array{id:int,json:string}> $trash Trash documents. @param bool $swapGalleries Gallery conversion intent. @return void Applies fixture state or injects failure. */
    function gallery_layout_migration_model_apply(\PDO $pdo, array $settings, array $smart, array $trash, bool $swapGalleries): void {
        $GLOBALS['layout_apply'] = compact('settings', 'smart', 'trash', 'swapGalleries');
        if (!empty($GLOBALS['layout_fail'])) throw new \RuntimeException('Injected database write failure');
        $GLOBALS['layout_state']['settings'] = array_replace($GLOBALS['layout_state']['settings'], $settings);
        $GLOBALS['layout_writes']++;
    }
    /** Finish the fixture transaction. @param \PDO $pdo Fixture connection. @param bool $commit Completion result. @return void Preserves committed settings or restores old values. */
    function gallery_layout_migration_model_finish(\PDO $pdo, bool $commit): void { if (!$commit) $GLOBALS['layout_state'] = $GLOBALS['layout_before']; }
}

namespace Gallery\Services {
    /**
     * Inject one owned sidecar sharing refusal without changing other file moves.
     * @param string $source Owned staging file.
     * @param string $destination Requested sidecar destination.
     * @return bool Native rename result or the controlled first refusal.
     */
    function rename(string $source, string $destination): bool {
        if (isset($GLOBALS['layout_rename_fixture']['path'])
            && str_replace('\\', '/', $GLOBALS['layout_rename_fixture']['path']) === str_replace('\\', '/', $destination)) {
            $GLOBALS['layout_rename_fixture']['attempts']++;
            if ($GLOBALS['layout_rename_fixture']['refusals'] > 0) {
                $GLOBALS['layout_rename_fixture']['refusals']--;
                if (isset($GLOBALS['layout_rename_fixture']['replacement'])) {
                    file_put_contents($destination, $GLOBALS['layout_rename_fixture']['replacement']);
                }
                return false;
            }
        }
        return \rename($source, $destination);
    }
}

namespace {
    require_once __DIR__ . '/../app/services/gallery_description_layout_compatibility.php';
    require_once __DIR__ . '/../app/views/public_gallery_cards.php';
    use function Gallery\Services\gallery_description_layout_upgrade_document;
    use function Gallery\Services\gallery_description_layout_upgrade_snapshot;
    use function Gallery\Services\gallery_description_layout_upgrade_sidecars;
    use function Gallery\Services\gallery_description_layout_migrate_legacy;
    use function Gallery\Views\view_public_gallery_description_layout_hook;

    /** Supply only the connection-local advisory-lock results used by the real model. */
    final class LayoutLockFixtureStatement extends \PDOStatement {
        /** Construct one scalar result. @param string|int $value Database identity or lock result. @return void Stores the scalar. */
        public function __construct(private string|int $value) {}
        /** Accept advisory-lock bindings. @param array<int,string|int>|null $params Lock name and optional wait. @return bool Successful fixture execution. */
        public function execute(?array $params = null): bool { return true; }
        /** Return the owned scalar. @param int $column Scalar column index. @return string|int Fixture scalar result. */
        public function fetchColumn(int $column = 0): mixed { return $this->value; }
    }

    /** PDO double exercises the real advisory-lock model without application bootstrap. */
    final class LayoutFixturePdo extends \PDO {
        /** Construct the inert fixture. @return void Opens no database connection. */
        public function __construct() {}
        /** Return this connection's database identity. @param string $query Identity SQL. @param int|null $fetchMode Optional PDO fetch mode. @param int|string ...$fetchModeArgs Optional mode arguments. @return \PDOStatement Identity statement. */
        public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false {
            if ($query !== 'SELECT DATABASE()') throw new \RuntimeException('Unexpected layout lock query.');
            return new LayoutLockFixtureStatement('layout_fixture');
        }
        /** Prepare this connection's lock acquisition or release. @param string $query Advisory-lock SQL. @param array<int,int> $options Driver options. @return \PDOStatement Advisory-lock statement. */
        public function prepare(string $query, array $options = []): \PDOStatement|false {
            if (!in_array($query, ['SELECT GET_LOCK(?, ?)', 'SELECT RELEASE_LOCK(?)'], true)) throw new \RuntimeException('Unexpected layout lock prepare.');
            return new LayoutLockFixtureStatement(1);
        }
    }
    /** Assert one compatibility postcondition. @param bool $condition Expected condition. @param string $message Failure description. @return void Throws on regression. */
    function layout_assert(bool $condition, string $message): void { if (!$condition) throw new \RuntimeException($message); }

    foreach (['vertical' => 'horizontal', 'horizontal' => 'vertical'] as $old => $next) {
        $converted = gallery_description_layout_upgrade_document(['description_layout' => $old, 'custom' => ['preserved' => true]]);
        layout_assert($converted['description_layout'] === $next, 'Explicit orientation must swap');
        layout_assert($converted['custom'] === ['preserved' => true], 'Unrelated metadata must survive');
        layout_assert(gallery_description_layout_upgrade_document($converted) === $converted, 'Marked documents must not swap twice');
        layout_assert(view_public_gallery_description_layout_hook($next) === $old, 'Historical custom CSS hook must retain appearance');
    }
    foreach ([null, '', 'inherit', 'invalid'] as $value) {
        layout_assert(gallery_description_layout_upgrade_document(['description_layout' => $value])['description_layout'] === $value, 'Inheritance/invalid values must not become explicit overrides');
    }
    layout_assert(!array_key_exists('description_layout', gallery_description_layout_upgrade_document([])), 'Missing override must remain absent');
    $snapshot = gallery_description_layout_upgrade_snapshot(['version' => 1, 'galleries' => [['description_layout' => 'horizontal'], ['description_layout' => null]], 'images' => [['title' => 'unchanged']]]);
    layout_assert($snapshot['galleries'][0]['description_layout'] === 'vertical' && $snapshot['galleries'][1]['description_layout'] === null, 'Trash conversion must preserve inheritance');
    layout_assert($snapshot['images'] === [['title' => 'unchanged']], 'Trash images must remain intact');

    $root = sys_get_temp_dir() . '/gallery-layout-' . bin2hex(random_bytes(8));
    mkdir($root);
    $path = $root . '/gallery.json';
    try {
        mkdir($root . '/unrelated');
        file_put_contents($root . '/unrelated/gallery.json', 'not a metadata document');
        file_put_contents($path, '{"description_layout":"vertical","custom":{},"title":"Keep me","description":"horizontal is prose"}');
        layout_assert(gallery_description_layout_upgrade_sidecars($root) === 1, 'Existing sidecar must convert');
        $sidecar = json_decode((string) file_get_contents($path));
        layout_assert($sidecar->description_layout === 'horizontal' && $sidecar->custom instanceof \stdClass && $sidecar->description === 'horizontal is prose', 'Preserve unrelated JSON structure and prose');
        layout_assert(gallery_description_layout_upgrade_sidecars($root) === 0, 'Marked sidecar replay must be harmless');
        layout_assert(file_get_contents($root . '/unrelated/gallery.json') === 'not a metadata document', 'Unrelated malformed metadata must remain untouched');
        $retryOriginal = '{"description_layout":"vertical","custom":{}}';
        file_put_contents($path, $retryOriginal);
        $GLOBALS['layout_rename_fixture'] = ['path' => $path, 'attempts' => 0, 'refusals' => 1];
        $retryRefused = false;
        try { gallery_description_layout_upgrade_sidecars($root); }
        catch (\RuntimeException) { $retryRefused = true; }
        if (PHP_OS_FAMILY === 'Windows') {
            layout_assert(!$retryRefused && $GLOBALS['layout_rename_fixture']['attempts'] >= 2 && $GLOBALS['layout_rename_fixture']['attempts'] <= 10, 'Windows sidecar commit must tolerate a bounded transient sharing refusal');
            layout_assert(json_decode((string) file_get_contents($path))->description_layout === 'horizontal', 'Retried commit must contain the complete converted document');
        } else {
            layout_assert($retryRefused && $GLOBALS['layout_rename_fixture']['attempts'] === 1 && file_get_contents($path) === $retryOriginal, 'Non-Windows refusal must retain one attempt and the prior document');
        }
        layout_assert((glob($root . '/.la*') ?: []) === [], 'Sidecar commit must clean its owned staging files');

        file_put_contents($path, $retryOriginal);
        $externalSidecar = '{"description_layout":"vertical","title":"Concurrent edit"}';
        $GLOBALS['layout_rename_fixture'] = ['path' => $path, 'attempts' => 0, 'refusals' => 1, 'replacement' => $externalSidecar];
        $retryRefused = false;
        try { gallery_description_layout_upgrade_sidecars($root); }
        catch (\RuntimeException $error) {
            $retryRefused = true;
            layout_assert($error->getMessage() === (PHP_OS_FAMILY === 'Windows' ? 'Gallery layout sidecar changed during migration.' : 'Gallery layout sidecar replacement failed.'), 'Concurrent replacement must refuse explicitly');
        }
        layout_assert($retryRefused && $GLOBALS['layout_rename_fixture']['attempts'] === 1 && file_get_contents($path) === $externalSidecar, 'Retry must never overwrite a concurrent sidecar edit');
        layout_assert((glob($root . '/.la*') ?: []) === [], 'Concurrent-edit refusal must remove only staging');
        unset($GLOBALS['layout_rename_fixture']);

        if (PHP_OS_FAMILY === 'Windows') {
            file_put_contents($path, $retryOriginal);
            layout_assert(chmod($path, 0444), 'Could not protect the sidecar fixture');
            $retryRefused = false;
            try { gallery_description_layout_upgrade_sidecars($root); }
            catch (\RuntimeException) { $retryRefused = true; }
            finally { chmod($path, 0666); }
            layout_assert($retryRefused && file_get_contents($path) === $retryOriginal, 'Read-only sidecar refusal must preserve the original document');
            layout_assert((glob($root . '/.la*') ?: []) === [], 'Read-only refusal must clean its owned staging file');
        }
        file_put_contents($path, '{"description_layout":"vertical","custom":{}}');
        mkdir($root . '/broken');
        file_put_contents($root . '/broken/gallery.json', '{"description_layout":');
        try { gallery_description_layout_upgrade_sidecars($root); throw new \LogicException('Expected malformed layout refusal'); }
        catch (\RuntimeException $error) { layout_assert(str_contains($error->getMessage(), 'invalid'), 'Malformed layout must refuse the plan'); }
        layout_assert(json_decode((string) file_get_contents($path))->description_layout === 'vertical', 'Complete sidecar preflight must precede the first write');
        unlink($root . '/broken/gallery.json');
        rmdir($root . '/broken');
        $GLOBALS['layout_state'] = ['settings' => ['theme_gallery_description_layout' => 'vertical', 'tag_page_gallery_description_layout' => 'horizontal'], 'upgrade' => true,
            'smart' => [['id' => 1, 'presentation_json' => '{"card_layout":"vertical","custom":{}}']], 'trash' => [['id' => 2, 'snapshot_json' => '{"version":1,"galleries":[{"description_layout":"horizontal"}]}']]];
        $GLOBALS['layout_writes'] = 0;
        $GLOBALS['layout_fail'] = true;
        try { gallery_description_layout_migrate_legacy(new LayoutFixturePdo(), [$root]); throw new \LogicException('Expected injected failure'); }
        catch (\RuntimeException $error) { layout_assert($error->getMessage() === 'Injected database write failure', 'Expected only injected failure'); }
        layout_assert($GLOBALS['layout_state']['settings']['theme_gallery_description_layout'] === 'vertical', 'Failed database conversion must roll back');
        layout_assert(json_decode((string) file_get_contents($path))->description_layout === 'horizontal', 'Interrupted conversion keeps its per-document checkpoint for safe retry');
        unset($GLOBALS['layout_fail']);
        gallery_description_layout_migrate_legacy(new LayoutFixturePdo(), [$root]);
        layout_assert($GLOBALS['layout_apply']['settings']['theme_gallery_description_layout'] === 'horizontal' && $GLOBALS['layout_apply']['settings']['tag_page_gallery_description_layout'] === 'vertical', 'Retry swaps DB preferences once despite already marked sidecar');
        layout_assert(json_decode($GLOBALS['layout_apply']['smart'][0]['json'])->card_layout === 'horizontal', 'Smart override must swap');
        layout_assert(json_decode($GLOBALS['layout_apply']['trash'][0]['json'])->galleries[0]->description_layout === 'vertical', 'Trash override must swap');
        gallery_description_layout_migrate_legacy(new LayoutFixturePdo(), [$root]);
        layout_assert($GLOBALS['layout_writes'] === 1, 'Completion marker must prevent a repeated database swap');
        $GLOBALS['layout_state'] = ['settings' => [], 'upgrade' => true, 'smart' => [], 'trash' => []];
        gallery_description_layout_migrate_legacy(new LayoutFixturePdo(), [$root]);
        layout_assert($GLOBALS['layout_apply']['settings']['theme_gallery_description_layout'] === 'horizontal' && !isset($GLOBALS['layout_apply']['settings']['tag_page_gallery_description_layout']), 'Upgrade implicit global appearance changes while tag inheritance stays absent');
        $GLOBALS['layout_state'] = ['settings' => [], 'upgrade' => false, 'smart' => [], 'trash' => []];
        gallery_description_layout_migrate_legacy(new LayoutFixturePdo(), []);
        layout_assert(!isset($GLOBALS['layout_apply']['settings']['theme_gallery_description_layout']) && !$GLOBALS['layout_apply']['swapGalleries'], 'Fresh install retains canonical default without overrides');
        $GLOBALS['layout_state'] = ['settings' => [], 'upgrade' => false, 'smart' => [], 'trash' => []];
        gallery_description_layout_migrate_legacy(new LayoutFixturePdo(), [$root]);
        layout_assert($GLOBALS['layout_apply']['settings']['theme_gallery_description_layout'] === 'horizontal', 'Existing orphan sidecars are durable upgrade evidence');
        echo "PASS gallery description layout compatibility\n";
    } finally {
        if (is_file($path)) unlink($path);
        foreach (['broken', 'unrelated'] as $directory) {
            if (is_file($root . '/' . $directory . '/gallery.json')) unlink($root . '/' . $directory . '/gallery.json');
            if (is_dir($root . '/' . $directory)) rmdir($root . '/' . $directory);
        }
        rmdir($root);
    }
}
