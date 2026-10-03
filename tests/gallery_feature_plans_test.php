<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_feature_plans_test.php
 * Module Type: Regression Test
 * Purpose: Verify precise staged subtree changes, coupled effects and stale-plan refusal.
 * Responsibilities: Exercise production service/controller through disposable model and authority seams.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Core {
    /** Supply a deterministic mutation timestamp.
     * @return string Fixture SQL timestamp.
     */
    function now_sql(): string { return '2026-10-02 12:00:00'; }
    /** Enforce the isolated administrator boundary.
     * @return void Throws before fixture reads on denial.
     */
    function require_admin(): void { if (!$GLOBALS['feature_auth']) throw new \RuntimeException('fixture authentication denied'); }
    /** Enforce isolated request authority.
     * @return void Throws before fixture reads on denial.
     */
    function verify_csrf(): void { if (!$GLOBALS['feature_csrf']) throw new \RuntimeException('fixture CSRF denied'); }
    /** Supply the isolated transport method.
     * @return string Current fixture request method.
     */
    function request_method(): string { return $GLOBALS['feature_method']; }
    /** Build routes without configuration access.
     * @param string $route Route identifier.
     * @param array<string,mixed> $params Query parameters.
     * @return string Synthetic route URL.
     */
    function url_for(string $route, array $params = []): string { return '/index.php?' . http_build_query(['page'=>$route]+$params); }
    /** Build a synthetic context URL.
     * @param array<string,mixed> $row Prepared gallery row.
     * @return string Safe fixture public route.
     */
    function gallery_public_url(array $row): string { return '/gallery/' . $row['id'] . '/'; }
}
namespace Gallery\Models {
    /** Return a disposable snapshot and observe writer-lock intent.
     * @param list<string> $features Required feature fields.
     * @param bool $lock Whether applying requires locks.
     * @return list<array<string,mixed>> Non-secret fixture rows.
     */
    function gallery_feature_plan_model_snapshot(array $features, bool $lock = false): array { $GLOBALS['feature_reads']++; $GLOBALS['feature_locked'] = $lock; return $GLOBALS['feature_rows']; }
    /** Emulate atomic rollback around the production service callback.
     * @param callable():array $operation Production comparison and writes.
     * @return array<string,mixed> Committed result.
     */
    function gallery_feature_plan_model_transaction(callable $operation): mixed {
        $before = $GLOBALS['feature_rows'];
        try { return $operation(); } catch (\Throwable $exception) { $GLOBALS['feature_rows'] = $before; throw $exception; }
    }
    /** Record an exact-row write without expanding descendants a second time.
     * @param list<int> $ids Exact changed row identifiers.
     * @param bool $enabled Requested value.
     * @param string $field Fixed fixture storage field.
     * @return int Number of disposable rows changed.
     */
    function feature_fixture_write(array $ids, bool $enabled, string $field): int {
        if (!$GLOBALS['feature_locked']) throw new \RuntimeException('writes require snapshot locks');
        $GLOBALS['feature_writes'][] = [$ids, $field, $enabled];
        foreach ($GLOBALS['feature_rows'] as &$row) if (in_array($row['id'], $ids, true)) { $row[$field] = (int) $enabled; $row['edit_revision']++; }
        unset($row);
        return count($ids);
    }
    /** Persist disposable explicit map flags.
     * @param list<int> $ids Exact scope.
     * @param bool|null $enabled Map value.
     * @param string $now Shared timestamp.
     * @return int Changed row count.
     */
    function gallery_model_set_gps_map_enabled(array $ids, ?bool $enabled, string $now): int { return feature_fixture_write($ids, (bool)$enabled, 'gps_map_enabled'); }
    /** Persist disposable filename flags.
     * @param list<int> $ids Exact scope.
     * @param bool $enabled Filename value.
     * @param string $now Shared timestamp.
     * @return int Changed row count.
     */
    function gallery_model_set_show_filenames(array $ids, bool $enabled, string $now): int { return feature_fixture_write($ids, $enabled, 'show_filenames'); }
    /** Persist disposable voting flags.
     * @param list<int> $ids Exact scope.
     * @param bool $enabled Voting value.
     * @param string $now Shared timestamp.
     * @return int Changed row count.
     */
    function gallery_model_set_voting_enabled(array $ids, bool $enabled, string $now): int { return feature_fixture_write($ids, $enabled, 'voting_enabled'); }
    /** Persist disposable game flags.
     * @param list<int> $ids Exact scope.
     * @param bool $enabled Game value.
     * @param string $now Shared timestamp.
     * @return int Changed row count.
     */
    function gallery_model_set_picture_game_enabled(array $ids, bool $enabled, string $now): int { return feature_fixture_write($ids, $enabled, 'picture_game_enabled'); }
}
namespace Gallery\Services {
    /** Resolve isolated effective feature policy.
     * @param string $key Capability identifier.
     * @return bool Prepared effective state.
     */
    function feature_capability_effective_enabled(string $key): bool { return !in_array($key, $GLOBALS['feature_disabled'], true); }
    /** Return shared explicit schema state without storage access.
     * @param string $feature Schema identifier.
     * @param string $table Required table.
     * @param list<string> $columns Required columns.
     * @return array{state:string} Fixture schema state.
     */
    function mutation_schema_table_columns_status(string $feature, string $table, array $columns): array { return ['state'=>$GLOBALS['feature_schema']]; }
    /** Require explicit available state before snapshot reads or writes.
     * @param array<string,mixed> $status Shared schema observation.
     * @param string $operation Semantic operation identifier.
     * @return void Throws for missing or unknown storage.
     */
    function mutation_schema_assert_available(array $status, string $operation): void { if ($status['state'] !== 'available') throw new \RuntimeException('fixture schema refusal'); }
    /** Supply verified GPS schema state.
     * @return array{state:string} Fixture observation.
     */
    function presentation_gps_exif_schema_status(): array { return ['state'=>$GLOBALS['feature_schema']]; }
    /** Supply verified nullable map inheritance storage.
     * @return array{state:string} Fixture observation.
     */
    function presentation_gps_override_schema_status(): array { return ['state'=>$GLOBALS['feature_schema']]; }
    /** Resolve fixture effective GPS through the production builder's snapshot callback.
     * @param array<string,mixed> $gallery Current disposable row.
     * @param callable(int):array $lookup Bounded production ancestor lookup.
     * @return bool Closest explicit preference or global fixture fallback.
     */
    function gallery_effective_gps_map_enabled(array $gallery, ?callable $lookup = null): bool {
        while (true) {
            if ($gallery['gps_map_enabled'] !== null) return (int)$gallery['gps_map_enabled'] === 1;
            if (empty($gallery['parent_id'])) return exif_gps_default_enabled();
            $gallery = $lookup((int)$gallery['parent_id']);
        }
    }
    /** Supply verified voting schema state.
     * @return array{state:string} Fixture observation.
     */
    function presentation_voting_schema_status(): array { return ['state'=>$GLOBALS['feature_schema']]; }
    /** Supply verified game schema state.
     * @return array{state:string} Fixture observation.
     */
    function presentation_picture_game_schema_status(): array { return ['state'=>$GLOBALS['feature_schema']]; }
    /** Read the independent global GPS preference.
     * @return bool Fixture default, never changed by plans.
     */
    function exif_gps_default_enabled(): bool { return true; }
    /** Exercise post-commit sidecar degradation without touching files.
     * @param list<int> $ids Changed gallery identifiers.
     * @param bool $bypassCache Whether fresh rows are needed.
     * @return void Throws only when failure injection is active.
     */
    function gallery_bulk_refresh_sidecars(array $ids, bool $bypassCache = false): void { if ($GLOBALS['feature_sidecar_fail']) throw new \RuntimeException('fixture sidecar failed'); }
    /** Find only disposable context data.
     * @param int $id Gallery identifier.
     * @param bool $fresh Whether bypassing cached state.
     * @return array<string,mixed>|null Fixture gallery.
     */
    function find_gallery(int $id, bool $fresh = false): ?array { foreach ($GLOBALS['feature_rows'] as $row) if ($row['id'] === $id) return $row; return null; }
    /** Supply controller fallbacks without loading translations.
     * @param string $key Translation identifier.
     * @param string $fallback English label.
     * @return string Safe presentation text.
     */
    function t(string $key, string $fallback = ''): string { return $fallback; }
}
namespace {
    require dirname(__DIR__) . '/app/helpers_mutation.php';
    require dirname(__DIR__) . '/app/services/gallery_feature_plans.php';
    require dirname(__DIR__) . '/app/controllers/admin_gallery_features.php';
    /** Reset all disposable state between independent cases.
     * @return void Supplies four galleries with a three-row nested branch.
     */
    function feature_fixture_reset(): void {
        $GLOBALS['feature_rows'] = [];
        foreach ([[1,0],[2,1],[3,2],[4,0]] as [$id,$parent]) $GLOBALS['feature_rows'][] = ['id'=>$id,'parent_id'=>$parent,'title'=>'Title <'.$id.'>','folder_path'=>'fixture/'.$id,'sort_order'=>$id,'edit_revision'=>1,'gps_map_enabled'=>null,'show_filenames'=>0,'voting_enabled'=>0,'picture_game_enabled'=>0];
        $GLOBALS['feature_writes'] = []; $GLOBALS['feature_reads']=0; $GLOBALS['feature_locked']=false; $GLOBALS['feature_schema']='available'; $GLOBALS['feature_disabled']=[]; $GLOBALS['feature_sidecar_fail']=false; $GLOBALS['feature_auth']=true; $GLOBALS['feature_csrf']=true; $GLOBALS['feature_method']='POST';
    }
    /** Assert meaningful production behavior with bounded failure context.
     * @param bool $condition Required invariant.
     * @param string $message Contract description.
     * @return void Throws on regression.
     */
    function feature_fixture_assert(bool $condition, string $message): void { if (!$condition) throw new \RuntimeException($message); }
    /** Prove refusal happens without a persistence call.
     * @param callable():mixed $operation Production use case under refusal.
     * @return void Requires an exception and no recorded writer calls.
     */
    function feature_fixture_refused(callable $operation): void { $refused=false; try { $operation(); } catch (\Throwable $exception) { $refused=true; } feature_fixture_assert($refused && $GLOBALS['feature_writes']===[], 'refused plan must not write'); }
    feature_fixture_reset();
    $game = [['root_id'=>1,'feature'=>'game','enabled'=>true]];
    $preview = \Gallery\Services\gallery_feature_plan_preview($game);
    feature_fixture_assert($GLOBALS['feature_writes']===[] && $preview['gallery_count']===3 && $preview['change_count']===6, 'preview is read-only and includes descendants plus voting coupling');
    feature_fixture_assert(array_column($preview['scope'],'title')===['Title <1>','Title <2>','Title <3>'], 'preview lists exact scoped gallery titles');
    $result=\Gallery\Services\gallery_feature_plan_apply($game,$preview['fingerprint']);
    feature_fixture_assert($result['entity_ids']===[1,2,3] && count($GLOBALS['feature_writes'])===6 && $GLOBALS['feature_rows'][3]['picture_game_enabled']===0, 'confirmed apply changes exact subtree and coupled voting');
    feature_fixture_reset();
    $ordered=[...$game,['root_id'=>2,'feature'=>'voting','enabled'=>false]];
    $preview=\Gallery\Services\gallery_feature_plan_preview($ordered);
    $result=\Gallery\Services\gallery_feature_plan_apply($ordered,$preview['fingerprint']);
    feature_fixture_assert($result['entity_ids']===[1] && $GLOBALS['feature_rows'][0]['voting_enabled']===1 && $GLOBALS['feature_rows'][1]['voting_enabled']===0 && $GLOBALS['feature_rows'][1]['picture_game_enabled']===0, 'last overlapping intent preserves coupled final state without subtree re-expansion');
    feature_fixture_reset();
    foreach ($GLOBALS['feature_rows'] as &$row) { $row['voting_enabled']=1; $row['picture_game_enabled']=1; } unset($row);
    $inverse=[['root_id'=>1,'feature'=>'voting','enabled'=>false],['root_id'=>2,'feature'=>'game','enabled'=>true]];
    $preview=\Gallery\Services\gallery_feature_plan_preview($inverse); $result=\Gallery\Services\gallery_feature_plan_apply($inverse,$preview['fingerprint']);
    feature_fixture_assert($result['entity_ids']===[1] && $GLOBALS['feature_rows'][0]['picture_game_enabled']===0 && $GLOBALS['feature_rows'][0]['voting_enabled']===0 && $GLOBALS['feature_rows'][1]['voting_enabled']===1 && $GLOBALS['feature_rows'][2]['picture_game_enabled']===1,'inverse overlap preserves later descendant game/voting ON while parent turns OFF');
    feature_fixture_reset(); $preview=\Gallery\Services\gallery_feature_plan_preview($game); $GLOBALS['feature_rows'][1]['parent_id']=4;
    feature_fixture_refused(/** Apply a stale topology fingerprint. @return array<string,mixed> Production result if incorrectly accepted. */ static fn():array=>\Gallery\Services\gallery_feature_plan_apply($game,$preview['fingerprint']));
    feature_fixture_reset(); $preview=\Gallery\Services\gallery_feature_plan_preview($game); $GLOBALS['feature_rows'][0]['voting_enabled']=1;
    feature_fixture_refused(/** Apply a stale feature-state fingerprint. @return array<string,mixed> Production result if incorrectly accepted. */ static fn():array=>\Gallery\Services\gallery_feature_plan_apply($game,$preview['fingerprint']));
    feature_fixture_reset(); $preview=\Gallery\Services\gallery_feature_plan_preview($game);
    feature_fixture_refused(/** Refuse intentions substituted after review. @return array<string,mixed> Production result if incorrectly accepted. */ static fn():array=>\Gallery\Services\gallery_feature_plan_apply([['root_id'=>4,'feature'=>'game','enabled'=>true]],$preview['fingerprint']));
    foreach (['missing','unknown'] as $schema) { feature_fixture_reset(); $GLOBALS['feature_schema']=$schema; feature_fixture_refused(/** Refuse unverifiable optional schema before reads. @return array<string,mixed> Production preview if incorrectly accepted. */ static fn():array=>\Gallery\Services\gallery_feature_plan_preview($game)); feature_fixture_assert($GLOBALS['feature_reads']===0,'unavailable schema must preserve lazy reads'); }
    feature_fixture_reset(); $GLOBALS['feature_disabled']=['picture_game']; feature_fixture_refused(/** Refuse disabled game without schema snapshot reads. @return array<string,mixed> Production preview if incorrectly accepted. */ static fn():array=>\Gallery\Services\gallery_feature_plan_preview($game)); feature_fixture_assert($GLOBALS['feature_reads']===0,'OFF policy must precede snapshots');
    feature_fixture_reset(); $GLOBALS['feature_disabled']=['image_voting']; feature_fixture_refused(/** Refuse game enabling when its coupled voting dependency is disabled. @return array<string,mixed> Production preview if incorrectly accepted. */ static fn():array=>\Gallery\Services\gallery_feature_plan_preview($game)); feature_fixture_assert($GLOBALS['feature_reads']===0,'coupled capability refusal must precede optional snapshots');
    feature_fixture_reset(); $maps=[['root_id'=>1,'feature'=>'maps','enabled'=>true]]; $preview=\Gallery\Services\gallery_feature_plan_preview($maps);
    feature_fixture_assert($preview['change_count']===3 && $preview['rows'][0]['changes'][0]['inherited']===true,'matching inherited map state still previews explicit storage change');
    $GLOBALS['feature_sidecar_fail']=true; $result=\Gallery\Services\gallery_feature_plan_apply($maps,$preview['fingerprint']);
    feature_fixture_assert($result['sidecar_refresh_failed'] && $result['entity_ids']===[1,2,3] && $GLOBALS['feature_rows'][0]['gps_map_enabled']===1,'post-commit sidecar failure preserves successful exact mutation');
    feature_fixture_reset(); $GLOBALS['feature_rows'][0]['gps_map_enabled']=0;
    $ancestorMaps=[['root_id'=>2,'feature'=>'maps','enabled'=>true]];
    $preview=\Gallery\Services\gallery_feature_plan_preview($ancestorMaps);
    feature_fixture_assert($preview['rows'][0]['changes'][0]['current']===false && $preview['rows'][1]['changes'][0]['current']===false,'exact map preview respects nearest OFF ancestor despite global ON default');
    feature_fixture_assert($preview['gallery_count']===2 && $GLOBALS['feature_writes']===[],'ancestor inheritance preview remains read-only in selected subtree');
    foreach (['feature_auth','feature_csrf'] as $boundary) { feature_fixture_reset(); $GLOBALS[$boundary]=false; $_POST=['intents'=>json_encode($game)]; feature_fixture_refused(/** Exercise controller authority before semantic reads. @return void Emits JSON only if incorrectly authorized. */ static function (): void { \Gallery\Controllers\cms_admin_gallery_features_plan(); }); feature_fixture_assert($GLOBALS['feature_reads']===0,'authority refusal precedes reads'); }
    feature_fixture_reset(); $_POST=['intents'=>json_encode($game)]; ob_start(); \Gallery\Controllers\cms_admin_gallery_features_plan(); $json=json_decode((string)ob_get_clean(),true);
    feature_fixture_assert($json['ok'] && $GLOBALS['feature_writes']===[],'actual preview endpoint never writes');
    $_POST['fingerprint']=$json['plan']['fingerprint']; ob_start(); \Gallery\Controllers\cms_admin_gallery_features_apply(); $json=json_decode((string)ob_get_clean(),true);
    feature_fixture_assert($json['ok'] && $json['mutation']['type']==='gallery.features.update' && $json['mutation']['entity_ids']===[1,2,3] && count($json['contexts'])===3,'actual apply emits canonical exact-id completion contexts');
    echo "PASS gallery feature plans\n";
}
